<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupAcrossSeveralScopesException;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupHasSeveralPositionsException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockInsideTransactionException;
use App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockTimeoutException;
use App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockWithoutScopeException;
use App\Portfolio\Shared\Infrastructure\Doctrine\PostgresAdvisoryOrderScopeLock;
use App\Portfolio\Shared\Infrastructure\Doctrine\TranslationGroupRefusal;
use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use App\Tests\Portfolio\Shared\Support\FakeOrderable;
use App\Tests\Portfolio\Shared\Support\FakeTranslatableContent;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\OptimisticLockException;
use DomainException;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Le verrou de périmètre d'ordre (issue #389) : ce qu'il garantit se lit depuis
 * une **autre** connexion, la seule qui voie un advisory lock comme le verrait
 * une requête concurrente — la même session peut toujours reprendre le sien.
 */
final class PostgresAdvisoryOrderScopeLockTest extends KernelTestCase
{
    use OpensProbeConnection;

    /** Deux périmètres quelconques : un périmètre est une classe d'entité, n'importe laquelle fait l'affaire ici. */
    private const string SCOPE = FakeTranslatableContent::class;

    private const string OTHER_SCOPE = FakeOrderable::class;

    /** Un décalage qui sort une entrée de la position de son groupe : le trigger différé refuse le COMMIT. */
    private const int OUT_OF_ITS_GROUP = 7;

    private Connection $probe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->probe = self::openProbeBeside($this->entityManager()->getConnection());
    }

    protected function tearDown(): void
    {
        $this->probe->close();
        parent::tearDown();
    }

    public function testTheScopeIsLockedForTheWholeOperationAndReleasedAfterIt(): void
    {
        $lockedDuringOperation = $this->lock()->withLock(self::SCOPE, fn (): bool => !$this->probeCanLock(self::SCOPE));

        self::assertTrue($lockedDuringOperation, 'Une autre session a pu prendre le verrou pendant l\'opération.');
        self::assertTrue($this->probeCanLock(self::SCOPE), 'Le verrou survit à l\'opération.');
    }

    public function testTheOperationsResultIsReturned(): void
    {
        $result = new stdClass();

        self::assertSame($result, $this->lock()->withLock(self::SCOPE, static fn (): stdClass => $result));
    }

    public function testAnotherScopeStaysFree(): void
    {
        $otherScopeFree = $this->lock()->withLock(self::SCOPE, fn (): bool => $this->probeCanLock(self::OTHER_SCOPE));

        self::assertTrue($otherScopeFree, 'Verrouiller un périmètre en bloque un autre.');
    }

    /**
     * Un advisory lock de transaction ne vit que le temps de sa transaction :
     * hors transaction, chaque requête en autocommit le relâcherait aussitôt.
     */
    public function testTheOperationRunsInsideATransaction(): void
    {
        $connection = $this->entityManager()->getConnection();

        self::assertTrue($this->lock()->withLock(self::SCOPE, $connection->isTransactionActive(...)));
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Un refus métier dans le verrou (409 « version déjà existante »…) est
     * une issue ordinaire : la transaction est annulée, le verrou relâché, et
     * l'EntityManager reste utilisable — `wrapInTransaction()` le fermerait.
     */
    public function testAFailingOperationReleasesTheLockAndLeavesTheEntityManagerOpen(): void
    {
        $refusal = new DomainException('refus métier simulé');
        $caught = null;

        try {
            $this->lock()->withLock(self::SCOPE, static fn (): never => throw $refusal);
        } catch (DomainException $exception) {
            $caught = $exception;
        }

        self::assertSame($refusal, $caught, 'L\'exception de l\'opération n\'est pas remontée telle quelle.');

        self::assertTrue($this->probeCanLock(self::SCOPE), 'Le verrou survit à l\'échec de l\'opération.');
        self::assertTrue($this->entityManager()->isOpen(), 'L\'échec de l\'opération a fermé l\'EntityManager.');
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
    }

    /**
     * Au-delà de `lock_timeout` (5 s pour un worker FPM, cf. www.prod.conf),
     * l'attente échoue sous un nom qui se cherche dans les journaux plutôt
     * qu'en `DriverException` anonyme. Reste un 500 `critical` : attendre
     * 5 s une écriture de quelques millisecondes est une anomalie.
     */
    public function testAWaitPastLockTimeoutFailsWithANamedExceptionWithoutRunningTheOperation(): void
    {
        $connection = $this->entityManager()->getConnection();
        $this->probe->executeQuery(
            'SELECT pg_advisory_lock(?, hashtext(?))',
            [PostgresAdvisoryOrderScopeLock::ADVISORY_NAMESPACE, self::SCOPE],
        );
        $connection->executeStatement("SET lock_timeout = '100ms'");
        $ran = false;
        $caught = null;

        try {
            $this->lock()->withLock(self::SCOPE, static function () use (&$ran): void {
                $ran = true;
            });
        } catch (OrderScopeLockTimeoutException $exception) {
            $caught = $exception;
        } finally {
            $connection->executeStatement('RESET lock_timeout');
        }

        self::assertInstanceOf(OrderScopeLockTimeoutException::class, $caught, "L'attente du verrou n'a pas échoué sous son nom.");
        self::assertStringContainsString(self::SCOPE, $caught->getMessage());
        self::assertInstanceOf(DriverException::class, $caught->getPrevious());
        self::assertFalse($ran, "L'opération a tourné sans le verrou.");
        self::assertTrue($this->entityManager()->isOpen());
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Issue #512 : une session que rien ne borne — la console, qui n'a pas le
     * `PGOPTIONS` de FPM ; les `app:*:seed` prennent ce verrou — attendait le
     * périmètre sans fin. Le verrou borne lui-même une telle attente, comme
     * ceux du compte et de l'attribution des identifiants. Le
     * `statement_timeout` n'est qu'un filet pour que le test échoue au lieu de
     * pendre : seul `lock_timeout` lève `55P03`.
     */
    public function testAWaitIsBoundedEvenWhenTheSessionSetsNoLockTimeout(): void
    {
        $connection = $this->entityManager()->getConnection();
        $this->probe->executeQuery(
            'SELECT pg_advisory_lock(?, hashtext(?))',
            [PostgresAdvisoryOrderScopeLock::ADVISORY_NAMESPACE, self::SCOPE],
        );
        $connection->executeStatement('SET lock_timeout = 0');
        $connection->executeStatement("SET statement_timeout = '3s'");
        $caught = null;

        try {
            (new PostgresAdvisoryOrderScopeLock($this->entityManager(), new NullLogger(), self::getContainer()->get(RereadUnderLock::class), '100ms'))->withLock(self::SCOPE, static fn (): null => null);
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            $connection->executeStatement('RESET statement_timeout');
            $connection->executeStatement('RESET lock_timeout');
        }

        self::assertInstanceOf(OrderScopeLockTimeoutException::class, $caught, \sprintf("L'attente d'une session sans lock_timeout n'est pas bornée : %s.", get_debug_type($caught)));
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Seule l'attente du verrou de périmètre est renommée : un délai dépassé
     * dans l'opération (verrou de ligne…) remonte tel quel.
     */
    public function testALockTimeoutRaisedByTheOperationItselfIsNotRenamed(): void
    {
        $timeout = null;

        try {
            $this->lock()->withLock(self::SCOPE, function (): void {
                $this->entityManager()->getConnection()->executeStatement("SET LOCAL lock_timeout = '100ms'");
                $this->probe->executeQuery('BEGIN');
                $this->probe->executeQuery('SELECT pg_advisory_xact_lock(1, 1)');
                $this->entityManager()->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(1, 1)');
            });
        } catch (Throwable $exception) {
            $timeout = $exception;
        } finally {
            $this->probe->executeQuery('ROLLBACK');
        }

        // OrderScopeLockTimeoutException n'est pas une DriverException : un
        // renommage indu ferait échouer cette assertion.
        self::assertInstanceOf(DriverException::class, $timeout, 'Le délai dépassé dans l\'opération a été renommé.');
    }

    /**
     * Issue #390 : le trigger différé refuse au COMMIT une transaction qui
     * laisse un groupe de traduction sur deux positions. Ce refus remonte sous
     * le nom de l'invariant, celui que lève déjà `ContentPlacement::inGroup()`,
     * avec le message de la base, qui nomme la table et le groupe. Avec
     * `Connection::transactional()`, il disparaissait derrière un
     * « There is no active transaction » : DBAL a déjà clos la transaction
     * quand le COMMIT échoue, et son `rollBack()` de repli levait par-dessus.
     */
    public function testACommitRefusedByTheGroupGuardFailsUnderTheInvariantsName(): void
    {
        $connection = $this->entityManager()->getConnection();

        try {
            [$french, $english] = $this->contributionPair();
            $caught = $this->failureOfALockedRun(static function () use ($connection, $english): void {
                $connection->executeStatement(
                    'UPDATE contribution SET position = position + ? WHERE id = ?',
                    [self::OUT_OF_ITS_GROUP, $english->getId()->toRfc4122()],
                );
            });
        } finally {
            $connection->executeStatement('DELETE FROM contribution');
        }

        self::assertInstanceOf(TranslationGroupHasSeveralPositionsException::class, $caught, \sprintf('Le refus du trigger est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertStringContainsString($french->getTranslationGroup()->toRfc4122(), $caught->getMessage(), 'Le message ne nomme pas le groupe.');
        self::assertStringContainsString('contribution', $caught->getMessage(), 'Le message ne nomme pas la table.');
        self::assertInstanceOf(DriverException::class, $caught->getPrevious());
        self::assertTrue($this->probeCanLock(Contribution::class), 'Le verrou survit au COMMIT refusé.');
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Issue #501 : le même trigger refuse un groupe de cartes « moi » rangé
     * dans deux catégories, sous un SQLSTATE voisin (`23T91`). Sur le chemin
     * applicatif, ce refus sort lui aussi sous le nom de son invariant, jamais
     * en `DriverException` brute ni sous celui des positions, que le groupe
     * respecte ici.
     */
    public function testACommitRefusedForAGroupAcrossCategoriesFailsUnderItsOwnName(): void
    {
        $connection = $this->entityManager()->getConnection();
        $meCards = self::getContainer()->get(AboutMeCardAdministratorInterface::class);
        $french = $meCards->create(Locale::FR, AboutMeCardCategory::HOBBY, 'T', 'D', null);

        try {
            $english = $meCards->create(Locale::EN, AboutMeCardCategory::HOBBY, 'T', 'D', null, $french->getTranslationGroup());
            $caught = $this->failureOfALockedRun(static function () use ($connection, $english): void {
                $connection->executeStatement(
                    'UPDATE about_me_card SET category = ? WHERE id = ?',
                    [AboutMeCardCategory::TECHNICAL->value, $english->getId()->toRfc4122()],
                );
            }, AboutMeCard::class);
        } finally {
            $connection->executeStatement('DELETE FROM about_me_card');
        }

        self::assertInstanceOf(TranslationGroupAcrossSeveralScopesException::class, $caught, \sprintf('Le refus du trigger est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertStringContainsString($french->getTranslationGroup()->toRfc4122(), $caught->getMessage(), 'Le message ne nomme pas le groupe.');
        self::assertStringContainsString('about_me_card', $caught->getMessage(), 'Le message ne nomme pas la table.');
        self::assertStringContainsString('hobby, technical', $caught->getMessage(), 'Le message ne nomme pas les catégories.');
        self::assertInstanceOf(DriverException::class, $caught->getPrevious());
        self::assertTrue($this->probeCanLock(AboutMeCard::class), 'Le verrou survit au COMMIT refusé.');
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Issue #506 : le nom de l'invariant n'est donné que par ce verrou, qui
     * possède le COMMIT. Une écriture qui le contourne — ici un `flush()` sur
     * l'EntityManager de l'application, celui que traverse la rédaction des
     * erreurs du pilote — reçoit le refus brut du trigger, que Doctrine
     * enveloppe : un COMMIT refusé sort de `UnitOfWork::commit()` en
     * `OptimisticLockException` « Commit failed », dont la cause est la
     * `DriverException` `23T90`, au message qui nomme encore le groupe et la
     * table (en SQL ou dans une migration, c'est cette `DriverException`
     * elle-même, épinglée par `TranslationGroupSinglePositionTest`). Limite
     * acceptée : aucune écriture applicative capable de réveiller le trigger
     * ne se fait hors verrou (#389, `OrderScopeLockCoverageTest`) ; restent les
     * migrations et le SQL. Doctrine ferme l'EntityManager sur ce COMMIT
     * refusé, comme le squelette le ferme sous verrou (#502).
     */
    public function testAWriteOutsideTheLockSurfacesAsDoctrinesCommitFailureWrappingTheRawRefusal(): void
    {
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();

        try {
            [$french, $english] = $this->contributionPair();
            $english->moveToPosition($english->getPosition() + self::OUT_OF_ITS_GROUP);
            $caught = $this->failureOf($entityManager->flush(...));
            $closed = !$entityManager->isOpen();
        } finally {
            self::getContainer()->get('doctrine')->resetManager();
            $connection->executeStatement('DELETE FROM contribution');
        }

        self::assertInstanceOf(OptimisticLockException::class, $caught, \sprintf('Le COMMIT refusé hors verrou n\'est pas remonté comme Doctrine le rapporte : %s.', get_debug_type($caught)));
        $refusal = $caught->getPrevious();
        self::assertInstanceOf(DriverException::class, $refusal, \sprintf('Le refus de la base n\'est plus la cause : %s.', get_debug_type($refusal)));
        self::assertSame(TranslationGroupRefusal::ON_SEVERAL_POSITIONS, $refusal->getSQLState(), $refusal->getMessage());
        self::assertStringContainsString($french->getTranslationGroup()->toRfc4122(), $refusal->getMessage(), 'Le message ne nomme plus le groupe.');
        self::assertStringContainsString('contribution', $refusal->getMessage(), 'Le message ne nomme plus la table.');
        self::assertTrue($closed, 'Le COMMIT refusé hors verrou a laissé l\'EntityManager ouvert.');
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Issue #502 : les `flush()` de l'opération ont réussi au point de
     * sauvegarde, puis la base a refusé le COMMIT. L'UnitOfWork tenait pour
     * écrit ce qu'elle venait d'annuler : une lecture sur le même EntityManager
     * rendait la position refusée, et `find()` une entrée jamais validée. Le
     * verrou ferme donc l'EntityManager, comme Doctrine le fait quand son
     * propre COMMIT échoue : sa carte d'identité vidée, une lecture relit la
     * base.
     */
    public function testAReadAfterACommitRefusedByTheGroupGuardSeesTheDatabase(): void
    {
        $entityManager = $this->entityManager();
        $neverCommitted = new Contribution(Locale::FR, 'Jamais validée', 'P', 'R', 'https://example.com', 'S', 'B', 0);

        try {
            [, $english] = $this->contributionPair();
            $storedPosition = $english->getPosition();
            $this->refuseTheNextCommit($english, $neverCommitted);

            self::assertSame($storedPosition, $entityManager->find(Contribution::class, $english->getId())?->getPosition(), 'Une lecture après le refus rend la position que la base a refusée.');
            self::assertNull($entityManager->find(Contribution::class, $neverCommitted->getId()), 'Une lecture après le refus rend une entrée que la base n\'a jamais validée.');
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM contribution');
        }
    }

    /**
     * Issue #502 : après un COMMIT refusé, une écriture sur le même
     * EntityManager échoue bruyamment au lieu de repartir de ce que la base a
     * refusé — hors verrou, où rien ne relisait l'état refusé, comme sous
     * verrou, où la relecture de #402 réparait déjà le périmètre (mesuré :
     * avant le correctif, les deux passaient).
     */
    public function testAWriteAfterACommitRefusedByTheGroupGuardFailsOnTheClosedEntityManager(): void
    {
        $entityManager = $this->entityManager();
        $container = self::getContainer();

        try {
            [$french, $english] = $this->contributionPair();
            $this->refuseTheNextCommit($english);

            $outsideTheLock = $this->failureOf(static fn () => $container->get(ContributionRepositoryInterface::class)->save(
                new Contribution(Locale::FR, 'Après le refus', 'P', 'R', 'https://example.com', 'S', 'B', 0),
            ));
            $underTheLock = $this->failureOf(static fn () => $container->get(ContributionAdministratorInterface::class)->reorder(
                [$french->getTranslationGroup()->toRfc4122()],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM contribution');
        }

        self::assertInstanceOf(EntityManagerClosed::class, $outsideTheLock, \sprintf('Une écriture hors verrou suivant un COMMIT refusé n\'a pas été refusée : %s.', get_debug_type($outsideTheLock)));
        self::assertInstanceOf(EntityManagerClosed::class, $underTheLock, \sprintf('Une écriture sous verrou suivant un COMMIT refusé n\'a pas été refusée : %s.', get_debug_type($underTheLock)));
        self::assertFalse($entityManager->getConnection()->isTransactionActive());
    }

    /**
     * Issue #549 : un refus levé par l'opération **après** son `flush()`
     * ferme l'EntityManager comme un COMMIT refusé (#502). Le ROLLBACK annule
     * ce que le flush avait écrit au point de sauvegarde ; ouvert, l'EntityManager
     * rendait la position annulée, une entrée jamais validée, et repartait de là.
     * Le refus remonte tel quel.
     */
    public function testARefusalRaisedAfterAWriteClosesTheEntityManager(): void
    {
        $entityManager = $this->entityManager();
        $refusal = new DomainException('refus métier après une écriture');
        $neverCommitted = new Contribution(Locale::FR, 'Jamais validée', 'P', 'R', 'https://example.com', 'S', 'B', 0);

        try {
            [$french, $english] = $this->contributionPair();
            $storedPositions = [$french->getPosition(), $english->getPosition()];

            $caught = $this->failureOfALockedRun(static function () use ($entityManager, $french, $english, $neverCommitted, $refusal): never {
                $french->moveToPosition($french->getPosition() + self::OUT_OF_ITS_GROUP);
                $english->moveToPosition($english->getPosition() + self::OUT_OF_ITS_GROUP);
                $entityManager->persist($neverCommitted);
                $entityManager->flush();

                throw $refusal;
            });

            self::assertSame($refusal, $caught, \sprintf('Le refus de l\'opération n\'est pas remonté tel quel : %s.', get_debug_type($caught)));
            self::assertFalse($entityManager->isOpen(), 'Un refus levé après une écriture a laissé l\'EntityManager ouvert sur ce que le ROLLBACK a annulé.');
            self::assertSame($storedPositions, [
                $entityManager->find(Contribution::class, $french->getId())?->getPosition(),
                $entityManager->find(Contribution::class, $english->getId())?->getPosition(),
            ], 'Une lecture après le refus rend les positions que le ROLLBACK a annulées.');
            self::assertNull($entityManager->find(Contribution::class, $neverCommitted->getId()), 'Une lecture après le refus rend une entrée que la base n\'a jamais validée.');

            $nextWrite = $this->failureOf(static fn () => self::getContainer()->get(ContributionRepositoryInterface::class)->save(
                new Contribution(Locale::FR, 'Après le refus', 'P', 'R', 'https://example.com', 'S', 'B', 0),
            ));
            self::assertInstanceOf(EntityManagerClosed::class, $nextWrite, \sprintf('Une écriture suivant le refus n\'a pas été refusée : %s.', get_debug_type($nextWrite)));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM contribution');
        }
    }

    /**
     * Issue #502 : tout COMMIT que la base refuse annule ce que les `flush()`
     * avaient écrit, quel qu'en soit le SQLSTATE. L'EntityManager est fermé de
     * même, et le refus remonte sans être renommé. Une contrainte d'unicité
     * différée sur une table temporaire, créée dans la transaction et annulée
     * avec elle, en fournit un.
     */
    public function testACommitRefusedForAnyOtherReasonAlsoClosesTheEntityManager(): void
    {
        $connection = $this->entityManager()->getConnection();

        $caught = $this->failureOfALockedRun(static function () use ($connection): void {
            $connection->executeStatement('CREATE TEMPORARY TABLE refused_at_commit (value int UNIQUE DEFERRABLE INITIALLY DEFERRED)');
            $connection->executeStatement('INSERT INTO refused_at_commit (value) VALUES (1), (1)');
        });

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught, \sprintf('Le refus du COMMIT est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertFalse($this->entityManager()->isOpen(), 'Un COMMIT refusé a laissé l\'EntityManager ouvert sur des écritures annulées.');
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Issue #526 : dans une transaction déjà ouverte, le COMMIT du verrou ne
     * serait qu'un `RELEASE SAVEPOINT`, et le trigger différé des groupes de
     * traduction refuserait au COMMIT de l'appelant, hors de portée du
     * verrou : en `DriverException` `23T90` brute, pas sous le nom de
     * l'invariant. Le verrou refuse donc de s'y imbriquer, avant de rien
     * écrire ni de lancer l'opération, et laisse la transaction de
     * l'appelant telle qu'il l'a ouverte.
     */
    public function testTheLockRefusesToRunInsideATransactionAlreadyOpen(): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->beginTransaction();
        $ran = false;
        $caught = null;

        try {
            $this->lock()->withLock(self::SCOPE, static function () use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        try {
            self::assertInstanceOf(OrderScopeLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertStringContainsString(self::SCOPE, $caught->getMessage());
            self::assertFalse($ran, "L'opération a tourné dans la transaction de l'appelant.");
            self::assertSame(1, $connection->getTransactionNestingLevel(), "La transaction de l'appelant a été touchée.");
            self::assertTrue($this->probeCanLock(self::SCOPE), 'Le verrou a été pris malgré le refus.');
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Le cas particulier d'un verrou d'ordre pris sous un autre : même
     * refus, et la transaction du premier est annulée comme après tout échec
     * de son opération.
     */
    public function testTheLockRefusesToNestInsideItself(): void
    {
        $caught = null;

        try {
            $this->lock()->withLock(self::SCOPE, fn (): mixed => $this->lock()->withLock(self::OTHER_SCOPE, static fn (): null => null));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(OrderScopeLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
        self::assertTrue($this->probeCanLock(self::SCOPE), 'Le verrou du premier périmètre a survécu au refus.');
    }

    /**
     * Issue #567 : `withLocks()` tient **chaque** périmètre demandé pour toute
     * l'opération, dans une seule transaction, et les relâche tous après elle.
     * Les périmètres sont pris triés, quel que soit l'ordre de la liste, pour
     * que deux appelants qui en demandent les mêmes ne s'interbloquent pas.
     */
    public function testWithLocksHoldsEveryScopeForTheWholeOperationAndReleasesThemAfterIt(): void
    {
        $connection = $this->entityManager()->getConnection();

        $observed = $this->lock()->withLocks([self::OTHER_SCOPE, self::SCOPE], fn (): array => [
            'scope locked' => !$this->probeCanLock(self::SCOPE),
            'other scope locked' => !$this->probeCanLock(self::OTHER_SCOPE),
            'in a transaction' => $connection->isTransactionActive(),
        ]);

        self::assertSame(['scope locked' => true, 'other scope locked' => true, 'in a transaction' => true], $observed);
        self::assertTrue($this->probeCanLock(self::SCOPE), 'Le premier verrou survit à l\'opération.');
        self::assertTrue($this->probeCanLock(self::OTHER_SCOPE), 'Le second verrou survit à l\'opération.');
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Issue #567, la réentrance bornée : sous `withLocks()`, un `withLock()`
     * d'un périmètre **déjà tenu** par ce même adaptateur — celui de chaque
     * `Administrator` qu'un seed enchaîne — exécute l'opération dans la
     * transaction ouverte, sans en ouvrir une autre ni reprendre le verrou :
     * le COMMIT reste celui de `withLocks()`, seul à nommer un refus différé.
     */
    public function testANestedWithLockOnAHeldScopeRunsInTheTransactionAlreadyOpen(): void
    {
        $connection = $this->entityManager()->getConnection();
        $lock = $this->lock();

        $observed = $lock->withLocks([self::SCOPE, self::OTHER_SCOPE], fn (): array => $lock->withLock(self::OTHER_SCOPE, fn (): array => [
            'nesting level' => $connection->getTransactionNestingLevel(),
            'scope still locked' => !$this->probeCanLock(self::OTHER_SCOPE),
        ]));

        self::assertSame(['nesting level' => 1, 'scope still locked' => true], $observed, 'Le withLock() imbriqué n\'a pas tourné dans la transaction de withLocks().');
        self::assertFalse($connection->isTransactionActive());
        self::assertTrue($this->probeCanLock(self::OTHER_SCOPE));
    }

    /**
     * La réentrance n'existe que sous `withLocks()` : un simple `withLock()`
     * n'alimente pas le registre, et un `withLock()` du **même** périmètre
     * sous lui reste refusé comme tout autre (#526) — le chemin du backoffice
     * ne change pas. `testTheLockRefusesToNestInsideItself` garde l'autre
     * périmètre.
     */
    public function testANestedWithLockUnderAPlainWithLockIsRefusedEvenOnTheSameScope(): void
    {
        $lock = $this->lock();
        $ran = false;
        $caught = null;

        try {
            $lock->withLock(self::SCOPE, static function () use ($lock, &$ran): void {
                $lock->withLock(self::SCOPE, static function () use (&$ran): void {
                    $ran = true;
                });
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(OrderScopeLockInsideTransactionException::class, $caught, \sprintf("L'imbrication sous un simple withLock() n'a pas été refusée : %s.", get_debug_type($caught)));
        self::assertFalse($ran, "L'opération imbriquée a tourné sous un simple withLock().");
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
        self::assertTrue($this->probeCanLock(self::SCOPE));
    }

    /**
     * Un délai dépassé sous `withLocks()` nomme le périmètre **dont l'attente
     * a échoué**, pas le premier de la liste triée : le témoin tient le
     * second, le premier se prend aussitôt. (Une fonction fléchée aurait figé
     * le premier à sa création, relecture de #567.) Le premier verrou, pris,
     * est relâché par le ROLLBACK.
     */
    public function testAWaitPastLockTimeoutOnTheSecondScopeIsNamedAfterThatScope(): void
    {
        $connection = $this->entityManager()->getConnection();
        $sorted = [self::SCOPE, self::OTHER_SCOPE];
        sort($sorted, \SORT_STRING);
        [$first, $second] = $sorted;
        $this->probe->executeQuery(
            'SELECT pg_advisory_lock(?, hashtext(?))',
            [PostgresAdvisoryOrderScopeLock::ADVISORY_NAMESPACE, $second],
        );
        $connection->executeStatement("SET lock_timeout = '100ms'");
        $caught = null;

        try {
            $this->lock()->withLocks([self::SCOPE, self::OTHER_SCOPE], static fn (): null => null);
        } catch (OrderScopeLockTimeoutException $exception) {
            $caught = $exception;
        } finally {
            $connection->executeStatement('RESET lock_timeout');
            $this->probe->executeQuery(
                'SELECT pg_advisory_unlock(?, hashtext(?))',
                [PostgresAdvisoryOrderScopeLock::ADVISORY_NAMESPACE, $second],
            );
        }

        self::assertInstanceOf(OrderScopeLockTimeoutException::class, $caught, "L'attente du second périmètre n'a pas échoué sous son nom.");
        self::assertStringContainsString($second, $caught->getMessage(), 'Le délai dépassé ne nomme pas le périmètre attendu.');
        self::assertStringNotContainsString($first, $caught->getMessage(), 'Le délai dépassé nomme le premier périmètre, pris sans attendre.');
        self::assertTrue($this->probeCanLock($first), 'Le premier verrou a survécu au ROLLBACK.');
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * La réentrance ne vaut que pour un périmètre tenu : un seed qui prend X
     * pendant que son `Administrator` écrit Y est une erreur de périmètre,
     * refusée bruyamment, comme toute imbrication (#526), avant toute écriture.
     */
    public function testANestedWithLockOnAScopeNotHeldIsRefused(): void
    {
        $lock = $this->lock();
        $ran = false;
        $caught = null;

        try {
            $lock->withLocks([self::SCOPE], static function () use ($lock, &$ran): void {
                $lock->withLock(self::OTHER_SCOPE, static function () use (&$ran): void {
                    $ran = true;
                });
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(OrderScopeLockInsideTransactionException::class, $caught, \sprintf("L'imbrication sur un périmètre non tenu n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
        self::assertStringContainsString(self::OTHER_SCOPE, $caught->getMessage());
        self::assertFalse($ran, "L'opération a tourné sans le verrou de son périmètre.");
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
        self::assertTrue($this->probeCanLock(self::SCOPE), 'Le verrou extérieur a survécu au refus.');
        self::assertTrue($this->probeCanLock(self::OTHER_SCOPE), 'Le périmètre refusé a été verrouillé.');
    }

    /**
     * `withLocks()` n'est jamais réentrant, même sur des périmètres tous tenus,
     * ni dans une transaction déjà ouverte : seul `withLock()` sur un périmètre
     * tenu l'est. Le squelette garde la règle de #526 pour tout le reste.
     */
    public function testWithLocksRefusesToNestInsideItselfOrInsideATransactionAlreadyOpen(): void
    {
        $lock = $this->lock();
        $connection = $this->entityManager()->getConnection();

        $nested = $this->failureOf(static fn (): mixed => $lock->withLocks([self::SCOPE], static fn (): mixed => $lock->withLocks([self::SCOPE], static fn (): null => null)));

        self::assertInstanceOf(OrderScopeLockInsideTransactionException::class, $nested, \sprintf("withLocks() imbriqué n'a pas été refusé : %s.", get_debug_type($nested)));
        self::assertFalse($connection->isTransactionActive());

        $connection->beginTransaction();

        try {
            $inOpenTransaction = $this->failureOf(static fn (): mixed => $lock->withLocks([self::SCOPE], static fn (): null => null));

            self::assertInstanceOf(OrderScopeLockInsideTransactionException::class, $inOpenTransaction, \sprintf("withLocks() dans une transaction ouverte n'a pas été refusé : %s.", get_debug_type($inOpenTransaction)));
            self::assertSame(1, $connection->getTransactionNestingLevel(), "La transaction de l'appelant a été touchée.");
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Sans périmètre, `withLocks()` n'aurait qu'une transaction à offrir, sans
     * rien sérialiser : une erreur de programmation, refusée avant d'ouvrir.
     */
    public function testWithLocksWithoutAnyScopeIsRefused(): void
    {
        // @phpstan-ignore argument.type (une liste vide viole le contrat de l'interface : c'est ce que le test éprouve)
        $caught = $this->failureOf(fn (): mixed => $this->lock()->withLocks([], static fn (): null => null));

        self::assertInstanceOf(OrderScopeLockWithoutScopeException::class, $caught, \sprintf("Une liste de périmètres vide n'a pas été refusée : %s.", get_debug_type($caught)));
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
    }

    /**
     * Les périmètres tenus sont oubliés quelle que soit l'issue — succès,
     * exception de l'opération — : un `withLock()` suivant ouvre bien sa
     * propre transaction, au lieu de tourner « réentrant » hors de toute
     * transaction sur un registre périmé.
     */
    public function testTheScopesHeldAreForgottenAfterASuccessAndAfterAFailure(): void
    {
        $lock = $this->lock();
        $connection = $this->entityManager()->getConnection();

        $lock->withLocks([self::SCOPE], static fn (): null => null);

        self::assertTrue($lock->withLock(self::SCOPE, $connection->isTransactionActive(...)), 'Après un succès, le périmètre est encore tenu pour verrouillé.');

        $this->failureOf(static fn (): mixed => $lock->withLocks([self::SCOPE], static fn (): never => throw new DomainException('refus simulé')));

        self::assertTrue($lock->withLock(self::SCOPE, $connection->isTransactionActive(...)), 'Après une exception, le périmètre est encore tenu pour verrouillé.');
    }

    /**
     * Le COMMIT de `withLocks()` est celui que le trigger différé refuse : le
     * refus sort sous le nom de l'invariant, même pour l'écriture faite par un
     * `withLock()` réentrant (#390, #506), l'EntityManager est fermé (#547), et
     * les périmètres tenus sont oubliés après ce refus aussi.
     */
    public function testACommitRefusedUnderWithLocksFailsUnderTheInvariantsNameAndForgetsTheScopes(): void
    {
        $lock = $this->lock();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();

        try {
            [$french, $english] = $this->contributionPair();
            $caught = $this->failureOf(static function () use ($lock, $entityManager, $english): void {
                $lock->withLocks([Contribution::class, self::OTHER_SCOPE], static function () use ($lock, $entityManager, $english): void {
                    $lock->withLock(Contribution::class, static function () use ($entityManager, $english): void {
                        $english->moveToPosition($english->getPosition() + self::OUT_OF_ITS_GROUP);
                        $entityManager->flush();
                    });
                });
            });
        } finally {
            $connection->executeStatement('DELETE FROM contribution');
        }

        self::assertInstanceOf(TranslationGroupHasSeveralPositionsException::class, $caught, \sprintf('Le refus du trigger est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertStringContainsString($french->getTranslationGroup()->toRfc4122(), $caught->getMessage(), 'Le message ne nomme pas le groupe.');
        self::assertFalse($entityManager->isOpen(), 'Un COMMIT refusé a laissé l\'EntityManager ouvert sur des écritures annulées.');
        self::assertFalse($connection->isTransactionActive());
        self::assertTrue($this->probeCanLock(Contribution::class), 'Le verrou survit au COMMIT refusé.');
        self::assertTrue($lock->withLock(Contribution::class, $connection->isTransactionActive(...)), 'Après un COMMIT refusé, le périmètre est encore tenu pour verrouillé.');
    }

    /**
     * Issue #402 sous `withLocks()` : les entrées de **chaque** périmètre que
     * l'EntityManager gérait avant le verrou sont relues une fois les verrous
     * pris — une ligne supprimée entre-temps est introuvable, dans l'un comme
     * dans l'autre. Le `withLock()` réentrant, lui, ne relit rien : rien n'a
     * pu être validé dans un périmètre tenu.
     */
    public function testWithLocksRereadsTheEntriesOfEveryScopeLoadedBeforeTheLocks(): void
    {
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();

        try {
            [$french] = $this->contributionPair();
            $product = self::getContainer()->get(WatchedProductAdministratorInterface::class)->create('php', 'PHP', VersionSource::MANUAL, '8.5');
            $this->probe->executeStatement('DELETE FROM contribution WHERE id = ?', [$french->getId()->toRfc4122()]);
            $this->probe->executeStatement('DELETE FROM watched_product WHERE id = ?', [$product->getId()->toRfc4122()]);

            $found = $this->lock()->withLocks([Contribution::class, WatchedProduct::class], static fn (): array => [
                'contribution' => $entityManager->find(Contribution::class, $french->getId()),
                'product' => $entityManager->find(WatchedProduct::class, $product->getId()),
            ]);
        } finally {
            $connection->executeStatement('DELETE FROM contribution');
            $connection->executeStatement('DELETE FROM watched_product');
        }

        self::assertSame(['contribution' => null, 'product' => null], $found, 'Une entrée supprimée avant les verrous a été rendue depuis la carte d\'identité.');
    }

    /**
     * Tente de prendre le verrou depuis la connexion témoin, et le relâche
     * aussitôt s'il l'a obtenu.
     *
     * @param class-string $scope
     */
    private function probeCanLock(string $scope): bool
    {
        $acquired = (bool) $this->probe->fetchOne(
            'SELECT pg_try_advisory_lock(?, hashtext(?))',
            [PostgresAdvisoryOrderScopeLock::ADVISORY_NAMESPACE, $scope],
        );
        if ($acquired) {
            $this->probe->fetchOne(
                'SELECT pg_advisory_unlock(?, hashtext(?))',
                [PostgresAdvisoryOrderScopeLock::ADVISORY_NAMESPACE, $scope],
            );
        }

        return $acquired;
    }

    /**
     * Une paire FR/EN de contributions, créée par l'administrateur.
     *
     * @return array{Contribution, Contribution}
     */
    private function contributionPair(): array
    {
        $administrator = self::getContainer()->get(ContributionAdministratorInterface::class);
        $french = $administrator->create(Locale::FR, 'T', 'P', 'R', 'https://example.com', 'S', 'B');

        return [$french, $administrator->create(Locale::EN, 'T', 'P', 'R', 'https://example.com', 'S', 'B', $french->getTranslationGroup())];
    }

    /**
     * Sous le verrou des contributions, sort `$entry` de la position de son
     * groupe et persiste `$neverCommitted`, puis vérifie que le trigger différé
     * refuse le COMMIT : le point de départ des tests de #502.
     */
    private function refuseTheNextCommit(Contribution $entry, ?Contribution $neverCommitted = null): void
    {
        $entityManager = $this->entityManager();
        $refusal = $this->failureOfALockedRun(static function () use ($entityManager, $entry, $neverCommitted): void {
            $entry->moveToPosition($entry->getPosition() + self::OUT_OF_ITS_GROUP);
            if (null !== $neverCommitted) {
                $entityManager->persist($neverCommitted);
            }
            $entityManager->flush();
        });

        self::assertInstanceOf(TranslationGroupHasSeveralPositionsException::class, $refusal, \sprintf('Le COMMIT n\'a pas été refusé par la garde des groupes : %s.', get_debug_type($refusal)));
    }

    /**
     * Lance `$operation` sous le verrou de `$scope` (les contributions, par
     * défaut) et rend ce que `withLock()` a levé — l'opération ou le COMMIT —,
     * `null` s'il a validé.
     *
     * @param Closure(): void $operation
     * @param class-string    $scope
     */
    private function failureOfALockedRun(Closure $operation, string $scope = Contribution::class): ?Throwable
    {
        return $this->failureOf(function () use ($operation, $scope): void {
            $this->lock()->withLock($scope, $operation);
        });
    }

    /**
     * Ce que `$run` a levé, `null` s'il a abouti.
     *
     * @param Closure(): mixed $run
     */
    private function failureOf(Closure $run): ?Throwable
    {
        try {
            $run();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * Instancié ici plutôt que tiré du conteneur : le câblage est l'affaire de
     * OrderScopeLockCoverageTest, qui passe par les administrateurs.
     */
    private function lock(): OrderScopeLockInterface
    {
        return new PostgresAdvisoryOrderScopeLock($this->entityManager(), new NullLogger(), self::getContainer()->get(RereadUnderLock::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
