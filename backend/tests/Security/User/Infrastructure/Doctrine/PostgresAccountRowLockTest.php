<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Infrastructure\Doctrine\AccountLockInsideTransactionException;
use App\Security\User\Infrastructure\Doctrine\AccountLockTimeoutException;
use App\Security\User\Infrastructure\Doctrine\PostgresAccountRowLock;
use App\Tests\Support\CommitRefusal;
use App\Tests\Support\OpensProbeConnection;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Le verrou du compte sur la base réelle (issues #435, #458). Sa raison d'être,
 * la sérialisation de deux processus, est couverte par
 * SendAccountInvitationConcurrencyTest et PasswordSetupActivationConcurrencyTest.
 */
final class PostgresAccountRowLockTest extends KernelTestCase
{
    use OpensProbeConnection;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('RESET lock_timeout');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testTheOperationSeesTheAccountAsTheLockReadsIt(): void
    {
        $user = $this->pendingUser();
        $this->em->getConnection()->executeStatement('UPDATE cpg_user SET activated_at = now() WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);

        $pending = $this->lock()->whileLocked($user, static fn (CpgUser $locked): bool => $locked->isPendingActivation());

        self::assertFalse($pending, 'L\'opération a lu l\'entité d\'avant le verrou.');
    }

    public function testAnAccountDeletedMeanwhileIsANotFound(): void
    {
        $user = $this->pendingUser();
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);

        $this->expectException(CpgUserNotFoundException::class);

        $this->lock()->whileLocked($user, static fn (CpgUser $locked): null => null);
    }

    /**
     * Un refus levé par l'opération (un lien déjà utilisé, 404) est une issue
     * ordinaire : il annule la transaction sans fermer l'EntityManager.
     */
    public function testARefusalInsideTheOperationLeavesTheEntityManagerOpen(): void
    {
        $user = $this->pendingUser();

        $refusal = null;
        try {
            // Refus d'un compte en attente : celui-ci l'est.
            $this->lock()->whileLocked($user, static function (CpgUser $locked): bool {
                if ($locked->isPendingActivation()) {
                    throw new LogicException('refus');
                }

                return true;
            });
        } catch (LogicException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(LogicException::class, $refusal, 'L\'exception de l\'opération aurait dû remonter.');

        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * L'attente est bornée dans la transaction elle-même : la console (le
     * worker) n'a pas le `lock_timeout` que FPM reçoit de `PGOPTIONS`.
     */
    public function testTheWaitIsBoundedInsideTheTransaction(): void
    {
        // Session sans borne, comme la console (le test de base n'a pas le
        // PGOPTIONS de FPM, mais ne pas en dépendre).
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');

        $user = $this->pendingUser();

        $timeout = $this->lock()->whileLocked($user, fn (CpgUser $locked): mixed => $this->em->getConnection()->fetchOne('SHOW lock_timeout'));

        self::assertSame('5s', $timeout);
    }

    /**
     * Au-delà du délai, une exception nommée — pas la `DriverException`
     * anonyme de PostgreSQL —, comme le verrou de périmètre d'ordre (#389).
     */
    public function testALockHeldBeyondTheTimeoutRaisesANamedException(): void
    {
        $user = $this->pendingUser();
        $holder = self::openProbeBeside($this->em->getConnection());
        $holder->beginTransaction();
        $holder->fetchOne('SELECT 1 FROM cpg_user WHERE id = :id FOR UPDATE', ['id' => $user->getId()->toRfc4122()]);

        // Filet (issue #512) : si la borne venait à manquer, l'attente
        // échouerait en 57014 après 3 s au lieu de pendre.
        // Session sans borne, comme la console : c'est le délai de
        // l'adaptateur qui doit s'appliquer.
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');
        $this->em->getConnection()->executeStatement("SET statement_timeout = '3s'");

        try {
            $this->expectException(AccountLockTimeoutException::class);
            (new PostgresAccountRowLock($this->em, new NullLogger(), '50ms'))->whileLocked($user, static fn (CpgUser $locked): null => null);
        } finally {
            $holder->rollBack();
            $holder->close();
            $this->em->getConnection()->executeStatement('RESET statement_timeout');
            $this->em->getConnection()->executeStatement('RESET lock_timeout');
        }
    }

    /**
     * Un COMMIT refusé remonte sous l'erreur de la base (issue #512). Avec
     * `Connection::transactional()`, un SQLSTATE hors de la courte liste de
     * DBAL disparaissait derrière « There is no active transaction » : DBAL a
     * déjà clos la transaction quand le COMMIT échoue, et son `rollBack()` de
     * repli levait par-dessus — le défaut que #390 a corrigé dans le verrou
     * d'ordre seulement.
     */
    public function testACommitRefusedByTheDatabaseSurfacesUnderItsOwnError(): void
    {
        $user = $this->pendingUser();
        $connection = $this->em->getConnection();
        $caught = null;

        try {
            $this->lock()->whileLocked($user, static function (CpgUser $locked) use ($connection): null {
                CommitRefusal::scheduleIn($connection);

                return null;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(DriverException::class, $caught, \sprintf('Le COMMIT refusé est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertSame(CommitRefusal::SQLSTATE, $caught->getSQLState());
        self::assertTrue($this->em->isOpen());
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * Issue #532 : dans une transaction déjà ouverte, le COMMIT du verrou ne
     * serait qu'un `RELEASE SAVEPOINT` : le `FOR UPDATE` du compte tiendrait
     * jusqu'au COMMIT de l'appelant, et le handler d'invitation enverrait son
     * lien avant que le jeton soit validé. Le verrou refuse donc de
     * s'imbriquer, avant de verrouiller la ligne ni de lancer l'opération, et
     * laisse la transaction de l'appelant telle qu'il l'a ouverte. Le message
     * nomme le compte par son UUID, jamais par son identifiant.
     */
    public function testTheLockRefusesToRunInsideATransactionAlreadyOpen(): void
    {
        $user = $this->pendingUser();
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);
        $connection->beginTransaction();
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileLocked($user, static function (CpgUser $locked) use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        try {
            self::assertInstanceOf(AccountLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertStringContainsString($user->getId()->toRfc4122(), $caught->getMessage());
            self::assertStringNotContainsString($user->getUserIdentifier(), $caught->getMessage(), "Le message cite l'identifiant du compte.");
            self::assertFalse($ran, "L'opération a tourné dans la transaction de l'appelant.");
            self::assertSame(1, $connection->getTransactionNestingLevel(), "La transaction de l'appelant a été touchée.");
            self::assertTrue($this->probeCanLockTheRow($probe, $user), 'La ligne du compte a été verrouillée malgré le refus.');
        } finally {
            $connection->rollBack();
            $probe->close();
        }
    }

    /**
     * Le cas particulier du verrou pris sous lui-même : même refus, et la
     * transaction du premier est annulée, la ligne rendue.
     */
    public function testTheLockRefusesToNestInsideItself(): void
    {
        $user = $this->pendingUser();
        $probe = self::openProbeBeside($this->em->getConnection());
        $caught = null;

        try {
            try {
                $this->lock()->whileLocked($user, fn (CpgUser $locked): mixed => $this->lock()->whileLocked($locked, static fn (CpgUser $again): null => null));
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            self::assertInstanceOf(AccountLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            self::assertTrue($this->probeCanLockTheRow($probe, $user), 'La ligne du compte est restée verrouillée après le refus.');
        } finally {
            $probe->close();
        }
    }

    private function lock(): AccountLockInterface
    {
        return self::getContainer()->get(AccountLockInterface::class);
    }

    private function pendingUser(): CpgUser
    {
        $user = new CpgUser('newcomer', '');
        $user->setEmail('newcomer@example.com');
        $user->markInvited(new DateTimeImmutable('-1 hour'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Tente la ligne depuis une autre session sans jamais l'attendre
     * (`SKIP LOCKED` : une ligne tenue n'est pas rendue), et la rend aussitôt
     * — hors transaction explicite, l'instruction seule la tient.
     */
    private function probeCanLockTheRow(Connection $probe, CpgUser $user): bool
    {
        return 1 === $probe->fetchOne(
            'SELECT 1 FROM cpg_user WHERE id = :id FOR UPDATE SKIP LOCKED',
            ['id' => $user->getId()->toRfc4122()],
        );
    }
}
