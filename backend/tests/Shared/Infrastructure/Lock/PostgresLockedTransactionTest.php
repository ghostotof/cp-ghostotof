<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use App\Tests\Support\CommitRefusal;
use App\Tests\Support\OpensProbeConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Le squelette commun aux trois verrous PostgreSQL (issue #512), sur la base
 * réelle. Ce que chaque verrou garantit en plus — la sérialisation de deux
 * processus — reste couvert par ses propres tests et par les tests de
 * concurrence (#364, #389, #435, #458).
 *
 * Le verrou pris ici est un advisory lock de transaction quelconque : seule
 * compte la forme du squelette autour de lui.
 */
final class PostgresLockedTransactionTest extends KernelTestCase
{
    use OpensProbeConnection;

    /** Une clé qu'aucun verrou de l'application n'emploie. */
    private const string LOCK_SQL = 'SELECT pg_advisory_xact_lock(512, 512)';

    private Connection $connection;

    private Connection $probe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->probe = self::openProbeBeside($this->connection);
    }

    protected function tearDown(): void
    {
        $this->probe->close();
        $this->connection->executeStatement('RESET lock_timeout');
        $this->connection->executeStatement('RESET statement_timeout');
        parent::tearDown();
    }

    public function testTheOperationRunsUnderTheLockInsideATransactionAndItsResultIsReturned(): void
    {
        $result = $this->transaction()->run(
            acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
            onTimeout: $this->unexpectedTimeout(...),
            operation: fn (): array => [$this->connection->isTransactionActive(), $this->probeCanTakeTheLock()],
        );

        self::assertSame([true, false], $result, "L'opération n'a pas tourné en transaction, verrou pris.");
        self::assertFalse($this->connection->isTransactionActive());
        self::assertTrue($this->probeCanTakeTheLock(), 'Le verrou a survécu au COMMIT.');
    }

    /**
     * La console n'a pas le `lock_timeout` que FPM reçoit de `PGOPTIONS` :
     * le squelette borne une attente que rien ne borne, pour la seule durée
     * de la transaction.
     */
    public function testAnUnboundedSessionGetsTheBoundForTheTransactionOnly(): void
    {
        $this->connection->executeStatement('SET lock_timeout = 0');

        $inside = $this->transaction('250ms')->run(
            acquire: static fn (): null => null,
            onTimeout: $this->unexpectedTimeout(...),
            operation: fn (): mixed => $this->connection->fetchOne('SHOW lock_timeout'),
        );

        self::assertSame('250ms', $inside);
        self::assertSame('0', $this->connection->fetchOne('SHOW lock_timeout'), 'La borne a survécu à la transaction.');
    }

    /**
     * Un délai que la session a déjà choisi — `PGOPTIONS` de FPM, un test —
     * est gardé tel quel : le squelette ne le rallonge ni ne le raccourcit.
     */
    public function testABoundAlreadyChosenByTheSessionIsKept(): void
    {
        $this->connection->executeStatement("SET lock_timeout = '1500ms'");

        $inside = $this->transaction('5s')->run(
            acquire: static fn (): null => null,
            onTimeout: $this->unexpectedTimeout(...),
            operation: fn (): mixed => $this->connection->fetchOne('SHOW lock_timeout'),
        );

        self::assertSame('1500ms', $inside);
    }

    public function testAWaitPastTheBoundIsRenamedWithoutRunningTheOperation(): void
    {
        $this->probe->beginTransaction();
        $this->probe->executeQuery(self::LOCK_SQL);
        $this->unboundedSessionWithASafetyNet();
        $named = new DomainException('délai nommé');
        $ran = false;
        $caught = null;

        try {
            $this->transaction('50ms')->run(
                acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                onTimeout: static fn (DriverException $timeout): Throwable => new DomainException('délai nommé', 0, $timeout),
                operation: static function () use (&$ran): void {
                    $ran = true;
                },
            );
        } catch (DomainException $exception) {
            $caught = $exception;
        } finally {
            $this->probe->rollBack();
        }

        self::assertInstanceOf(DomainException::class, $caught, "L'attente n'est pas remontée sous le nom donné.");
        self::assertSame($named->getMessage(), $caught->getMessage());
        self::assertInstanceOf(DriverException::class, $caught->getPrevious());
        self::assertSame(PostgresLockedTransaction::LOCK_NOT_AVAILABLE, $caught->getPrevious()->getSQLState());
        self::assertFalse($ran, "L'opération a tourné sans le verrou.");
        self::assertFalse($this->connection->isTransactionActive());
    }

    /**
     * Seule l'attente du verrou est renommée : un délai dépassé dans
     * l'opération (un verrou de ligne…) remonte tel quel.
     */
    public function testALockTimeoutInsideTheOperationIsNotRenamed(): void
    {
        $this->probe->beginTransaction();
        $this->probe->executeQuery('SELECT pg_advisory_xact_lock(512, 513)');
        $this->unboundedSessionWithASafetyNet();
        $caught = null;

        try {
            $this->transaction('50ms')->run(
                acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                onTimeout: static fn (DriverException $timeout): Throwable => new DomainException('renommé à tort', 0, $timeout),
                operation: fn (): mixed => $this->connection->executeQuery('SELECT pg_advisory_xact_lock(512, 513)'),
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            $this->probe->rollBack();
        }

        self::assertInstanceOf(DriverException::class, $caught, \sprintf("Le délai dépassé dans l'opération a été renommé : %s.", get_debug_type($caught)));
        self::assertSame(PostgresLockedTransaction::LOCK_NOT_AVAILABLE, $caught->getSQLState());
        self::assertFalse($this->connection->isTransactionActive());
    }

    /**
     * Un refus de l'acquisition elle-même (la ligne du compte disparue…)
     * remonte tel quel, transaction annulée.
     */
    public function testARefusalRaisedByTheAcquisitionRollsBack(): void
    {
        $refusal = new LogicException('acquisition refusée');

        try {
            $this->transaction()->run(
                acquire: static fn (): never => throw $refusal,
                onTimeout: $this->unexpectedTimeout(...),
                operation: static fn (): null => null,
            );
            self::fail("Le refus de l'acquisition aurait dû remonter.");
        } catch (LogicException $exception) {
            self::assertSame($refusal, $exception);
        }

        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testAFailingOperationRollsBackAndReleasesTheLock(): void
    {
        $refusal = new DomainException('refus métier');
        $this->connection->executeStatement('CREATE TEMPORARY TABLE locked_write (k int)');

        $caught = null;

        try {
            try {
                $this->transaction()->run(
                    acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                    onTimeout: $this->unexpectedTimeout(...),
                    operation: function () use ($refusal): never {
                        $this->connection->executeStatement('INSERT INTO locked_write (k) VALUES (1)');

                        throw $refusal;
                    },
                );
            } catch (DomainException $exception) {
                $caught = $exception;
            }

            self::assertSame($refusal, $caught, "L'exception de l'opération n'est pas remontée telle quelle.");
            self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM locked_write'), "L'écriture de l'opération refusée a été validée.");
            self::assertFalse($this->connection->isTransactionActive());
            self::assertTrue($this->probeCanTakeTheLock(), "Le verrou a survécu à l'échec de l'opération.");
        } finally {
            $this->connection->executeStatement('DROP TABLE locked_write');
        }
    }

    /**
     * Un COMMIT refusé remonte sous l'erreur de la base, sans `rollBack()`
     * de repli : PostgreSQL a déjà annulé la transaction, et DBAL l'a déjà
     * close — `Connection::transactional()` levait « There is no active
     * transaction » par-dessus.
     */
    public function testACommitRefusedByTheDatabaseSurfacesUnderItsOwnError(): void
    {
        $caught = null;

        try {
            $this->transaction()->run(
                acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                onTimeout: $this->unexpectedTimeout(...),
                operation: fn () => CommitRefusal::scheduleIn($this->connection),
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(DriverException::class, $caught, \sprintf('Le COMMIT refusé est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertSame(CommitRefusal::SQLSTATE, $caught->getSQLState());
        self::assertFalse($this->connection->isTransactionActive());
        self::assertTrue($this->probeCanTakeTheLock(), 'Le verrou a survécu au COMMIT refusé.');
    }

    /**
     * Un verrou peut nommer un refus du COMMIT qu'il reconnaît (le trigger
     * des groupes de traduction, #390) ; un refus qu'il ne reconnaît pas
     * remonte tel quel.
     */
    public function testACommitRefusalCanBeRenamedByTheLockThatRecognisesIt(): void
    {
        $recognised = null;
        $unrecognised = null;

        try {
            $this->transaction()->run(
                acquire: static fn (): null => null,
                onTimeout: $this->unexpectedTimeout(...),
                operation: fn () => CommitRefusal::scheduleIn($this->connection),
                onCommitRefused: static fn (DriverException $refusal): ?Throwable => CommitRefusal::SQLSTATE === $refusal->getSQLState() ? new DomainException('invariant', 0, $refusal) : null,
            );
        } catch (Throwable $exception) {
            $recognised = $exception;
        }

        try {
            $this->transaction()->run(
                acquire: static fn (): null => null,
                onTimeout: $this->unexpectedTimeout(...),
                operation: fn () => CommitRefusal::scheduleIn($this->connection),
                onCommitRefused: static fn (DriverException $refusal): ?Throwable => null,
            );
        } catch (Throwable $exception) {
            $unrecognised = $exception;
        }

        self::assertInstanceOf(DomainException::class, $recognised);
        self::assertInstanceOf(DriverException::class, $recognised->getPrevious());
        self::assertInstanceOf(DriverException::class, $unrecognised, 'Un refus non reconnu a été renommé.');
        self::assertFalse($this->connection->isTransactionActive());
    }

    /**
     * Une session sans `lock_timeout`, avec un filet : sans la borne du
     * squelette, l'attente échoue en `57014` après 3 s au lieu de pendre.
     */
    private function unboundedSessionWithASafetyNet(): void
    {
        $this->connection->executeStatement('SET lock_timeout = 0');
        $this->connection->executeStatement("SET statement_timeout = '3s'");
    }

    private function transaction(string $lockTimeout = PostgresLockedTransaction::DEFAULT_LOCK_TIMEOUT): PostgresLockedTransaction
    {
        return new PostgresLockedTransaction($this->connection, $lockTimeout);
    }

    private function unexpectedTimeout(DriverException $timeout): Throwable
    {
        return new LogicException('Délai inattendu dans ce test.', 0, $timeout);
    }

    /**
     * Tente le verrou depuis une autre session, et le rend aussitôt s'il l'a
     * eu : hors transaction explicite, un verrou de transaction ne vit que le
     * temps de l'instruction.
     */
    private function probeCanTakeTheLock(): bool
    {
        return true === $this->probe->fetchOne('SELECT pg_try_advisory_xact_lock(512, 512)');
    }
}
