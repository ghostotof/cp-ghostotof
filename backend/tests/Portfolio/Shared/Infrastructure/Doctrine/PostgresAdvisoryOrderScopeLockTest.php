<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupHasSeveralPositionsException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockTimeoutException;
use App\Portfolio\Shared\Infrastructure\Doctrine\PostgresAdvisoryOrderScopeLock;
use App\Tests\Portfolio\Shared\Support\FakeOrderable;
use App\Tests\Portfolio\Shared\Support\FakeTranslatableContent;
use App\Tests\Support\OpensProbeConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
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
            (new PostgresAdvisoryOrderScopeLock($this->entityManager(), new NullLogger(), '100ms'))->withLock(self::SCOPE, static fn (): null => null);
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
        $administrator = self::getContainer()->get(ContributionAdministratorInterface::class);
        $french = $administrator->create(Locale::FR, 'T', 'P', 'R', 'https://example.com', 'S', 'B');
        $english = $administrator->create(Locale::EN, 'T', 'P', 'R', 'https://example.com', 'S', 'B', $french->getTranslationGroup());
        $caught = null;

        try {
            $this->lock()->withLock(Contribution::class, static fn (): int|string => $connection->executeStatement(
                'UPDATE contribution SET position = position + 7 WHERE id = ?',
                [$english->getId()->toRfc4122()],
            ));
        } catch (Throwable $exception) {
            $caught = $exception;
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
     * Instancié ici plutôt que tiré du conteneur : le câblage est l'affaire de
     * OrderScopeLockCoverageTest, qui passe par les administrateurs.
     */
    private function lock(): OrderScopeLockInterface
    {
        return new PostgresAdvisoryOrderScopeLock($this->entityManager(), new NullLogger());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
