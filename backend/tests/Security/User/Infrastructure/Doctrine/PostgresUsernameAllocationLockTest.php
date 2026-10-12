<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\UsernameAllocationLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Infrastructure\Doctrine\PostgresUsernameAllocationLock;
use App\Security\User\Infrastructure\Doctrine\UsernameAllocationLockInsideTransactionException;
use App\Security\User\Infrastructure\Doctrine\UsernameAllocationLockTimeoutException;
use App\Tests\Support\CommitRefusal;
use App\Tests\Support\OpensProbeConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Le verrou d'attribution des identifiants sur la base réelle (issue #364).
 * Sa raison d'être, la sérialisation de deux invitations, est couverte par
 * InviteUsernameConcurrencyTest.
 */
final class PostgresUsernameAllocationLockTest extends KernelTestCase
{
    use OpensProbeConnection;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('RESET lock_timeout');
        $this->em->getConnection()->executeStatement("DELETE FROM cpg_user WHERE username = 'refused'");
        parent::tearDown();
    }

    /**
     * Pris pendant l'opération, vu d'une autre session ; rendu à sa fin, avec
     * la transaction qui le portait.
     */
    public function testTheLockIsHeldDuringTheOperationAndReleasedWithItsTransaction(): void
    {
        $probe = self::openProbeBeside($this->em->getConnection());

        try {
            $heldDuring = $this->lock()->whileAllocating(fn (): bool => !$this->probeCanTakeTheLock($probe));

            self::assertTrue($heldDuring, 'Une autre session a pu prendre le verrou pendant l\'opération.');
            self::assertTrue($this->probeCanTakeTheLock($probe), 'Le verrou a survécu à l\'opération.');
            self::assertFalse($this->em->getConnection()->isTransactionActive());
        } finally {
            $probe->close();
        }
    }

    public function testTheOperationRunsInsideATransactionAndItsResultIsReturned(): void
    {
        $result = $this->lock()->whileAllocating(fn (): string => $this->em->getConnection()->isTransactionActive() ? 'en transaction' : 'hors transaction');

        self::assertSame('en transaction', $result);
    }

    /**
     * Un refus levé par l'opération remonte tel quel, annule la transaction
     * et rend le verrou, sans fermer l'EntityManager.
     */
    public function testARefusalInsideTheOperationRollsBackAndReleasesTheLock(): void
    {
        $probe = self::openProbeBeside($this->em->getConnection());

        try {
            try {
                // Une écriture, puis le refus.
                $this->lock()->whileAllocating(function (): bool {
                    $this->em->persist(new CpgUser('refused', ''));
                    $this->em->flush();
                    if ($this->em->getConnection()->isTransactionActive()) {
                        throw new LogicException('refus');
                    }

                    return false;
                });
                self::fail('L\'exception de l\'opération aurait dû remonter.');
            } catch (LogicException $exception) {
                self::assertSame('refus', $exception->getMessage());
            }

            self::assertSame(0, $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM cpg_user WHERE username = 'refused'"), 'L\'écriture de l\'opération refusée a été validée.');
            self::assertTrue($this->em->isOpen());
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            self::assertTrue($this->probeCanTakeTheLock($probe), 'Le verrou a survécu au refus.');
        } finally {
            $probe->close();
        }
    }

    /**
     * L'attente est bornée dans la transaction elle-même : la console n'a pas
     * le `lock_timeout` que FPM reçoit de `PGOPTIONS`.
     */
    public function testTheWaitIsBoundedInsideTheTransaction(): void
    {
        // Session sans borne, comme la console (le test de base n'a pas le
        // PGOPTIONS de FPM, mais ne pas en dépendre).
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');

        $timeout = $this->lock()->whileAllocating(fn (): mixed => $this->em->getConnection()->fetchOne('SHOW lock_timeout'));

        self::assertSame('5s', $timeout);
    }

    /**
     * Au-delà du délai, une exception nommée — pas la `DriverException`
     * anonyme de PostgreSQL —, comme les verrous du compte et d'ordre.
     */
    public function testALockHeldBeyondTheTimeoutRaisesANamedException(): void
    {
        $holder = self::openProbeBeside($this->em->getConnection());
        $holder->beginTransaction();
        $holder->executeQuery(
            'SELECT pg_advisory_xact_lock(?, ?)',
            [PostgresUsernameAllocationLock::ADVISORY_NAMESPACE, PostgresUsernameAllocationLock::ADVISORY_KEY],
        );

        // Filet (issue #512) : si la borne venait à manquer, l'attente
        // échouerait en 57014 après 3 s au lieu de pendre.
        // Session sans borne, comme la console : c'est le délai de
        // l'adaptateur qui doit s'appliquer.
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');
        $this->em->getConnection()->executeStatement("SET statement_timeout = '3s'");

        try {
            $this->expectException(UsernameAllocationLockTimeoutException::class);
            (new PostgresUsernameAllocationLock($this->em, new NullLogger(), '50ms'))->whileAllocating(static fn (): null => null);
        } finally {
            $holder->rollBack();
            $holder->close();
            $this->em->getConnection()->executeStatement('RESET statement_timeout');
            $this->em->getConnection()->executeStatement('RESET lock_timeout');
        }
    }

    /**
     * Un COMMIT refusé remonte sous l'erreur de la base, le verrou rendu avec
     * la transaction (issue #512) — `Connection::transactional()` le masquait,
     * voir PostgresAccountRowLockTest.
     */
    public function testACommitRefusedByTheDatabaseSurfacesUnderItsOwnError(): void
    {
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);
        $caught = null;

        try {
            try {
                $this->lock()->whileAllocating(static function () use ($connection): null {
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
            self::assertTrue($this->probeCanTakeTheLock($probe), 'Le verrou a survécu au COMMIT refusé.');
        } finally {
            $probe->close();
        }
    }

    /**
     * Issue #532 : dans une transaction déjà ouverte, le COMMIT du verrou ne
     * serait qu'un `RELEASE SAVEPOINT`. `CpgUserInviter::invite()` publie
     * l'invitation au retour de `whileAllocating()`, qu'il tient pour le
     * COMMIT : le worker chercherait un compte encore invisible, n'en
     * trouverait aucun et abandonnerait le message sans réessai. Le verrou
     * refuse donc de s'imbriquer, avant de rien prendre ni lancer, et laisse
     * la transaction de l'appelant telle qu'il l'a ouverte.
     */
    public function testTheLockRefusesToRunInsideATransactionAlreadyOpen(): void
    {
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);
        $connection->beginTransaction();
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileAllocating(static function () use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        try {
            self::assertInstanceOf(UsernameAllocationLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertFalse($ran, "L'opération a tourné dans la transaction de l'appelant.");
            self::assertSame(1, $connection->getTransactionNestingLevel(), "La transaction de l'appelant a été touchée.");
            self::assertTrue($this->probeCanTakeTheLock($probe), 'Le verrou a été pris malgré le refus.');
        } finally {
            $connection->rollBack();
            $probe->close();
        }
    }

    /**
     * Le cas particulier du verrou pris sous lui-même : même refus, et la
     * transaction du premier est annulée, son verrou rendu.
     */
    public function testTheLockRefusesToNestInsideItself(): void
    {
        $probe = self::openProbeBeside($this->em->getConnection());
        $caught = null;

        try {
            try {
                $this->lock()->whileAllocating(fn (): mixed => $this->lock()->whileAllocating(static fn (): null => null));
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            self::assertInstanceOf(UsernameAllocationLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            self::assertTrue($this->probeCanTakeTheLock($probe), 'Le verrou du premier a survécu au refus.');
        } finally {
            $probe->close();
        }
    }

    private function lock(): UsernameAllocationLockInterface
    {
        return self::getContainer()->get(UsernameAllocationLockInterface::class);
    }

    /**
     * Tente le verrou depuis une autre session, et le rend aussitôt s'il l'a
     * eu : `pg_try_advisory_xact_lock` hors transaction explicite ne le garde
     * que le temps de l'instruction.
     */
    private function probeCanTakeTheLock(Connection $probe): bool
    {
        return true === $probe->fetchOne(
            'SELECT pg_try_advisory_xact_lock(?, ?)',
            [PostgresUsernameAllocationLock::ADVISORY_NAMESPACE, PostgresUsernameAllocationLock::ADVISORY_KEY],
        );
    }
}
