<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use App\Shared\Infrastructure\Lock\LockedTransactionRollbackFailedException;
use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use App\Tests\Support\CommitRefusal;
use App\Tests\Support\OpensProbeConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\CommitFailedRollbackOnly;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
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

    /** Le message de l'exception qu'un verrou donnerait à son attente dépassée. */
    private const string NAMED_TIMEOUT = 'délai nommé';

    /**
     * Le message d'une cause qui ne doit jamais atteindre le journal : celui
     * d'une `DriverException` peut citer les paramètres de la requête (une
     * adresse, un identifiant), celui d'un refus métier une donnée reçue.
     */
    private const string CAUSE_MESSAGE = 'cause avec une donnée à ne pas journaliser';

    private Connection $connection;

    private Connection $probe;

    private TestHandler $logs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->probe = self::openProbeBeside($this->connection);
        $this->logs = new TestHandler();
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
        $ran = false;
        $caught = null;

        try {
            $this->transaction('50ms')->run(
                acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                onTimeout: static fn (DriverException $timeout): Throwable => new DomainException(self::NAMED_TIMEOUT, 0, $timeout),
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
        self::assertSame(self::NAMED_TIMEOUT, $caught->getMessage());
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
     * Un COMMIT que DBAL refuse lui-même, avant d'écrire quoi que ce soit
     * (`setRollbackOnly()`), laisse sa transaction ouverte : le squelette
     * l'annule, sans quoi la session — un worker qui vit longtemps — garde
     * la transaction et le verrou. `Connection::transactional()` le faisait.
     */
    public function testACommitRefusedByDbalBeforeTheDatabaseStillReleasesTheLock(): void
    {
        $caught = null;

        try {
            $this->transaction()->run(
                acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                onTimeout: $this->unexpectedTimeout(...),
                operation: $this->connection->setRollbackOnly(...),
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(CommitFailedRollbackOnly::class, $caught, \sprintf('Le refus de DBAL est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertFalse($this->connection->isTransactionActive(), 'La transaction est restée ouverte.');
        self::assertTrue($this->probeCanTakeTheLock(), 'Le verrou a survécu au COMMIT refusé par DBAL.');
    }

    /**
     * Issue #526 : la session meurt entre l'échec de l'opération et le
     * ROLLBACK — `pg_terminate_backend()`, le `57P01 admin_shutdown` d'un
     * redémarrage de PostgreSQL —, et le ROLLBACK échoue à son tour. La cause
     * d'origine remonte quand même, telle quelle : c'est elle que l'appelant
     * reconnaît (un refus métier, un `55P03` renommé) et elle qui dit ce qui
     * s'est passé. L'échec du ROLLBACK est journalisé à côté, sans son
     * message ni celui de la cause, et la session morte est fermée : la
     * requête suivante en rouvre une.
     */
    public function testTheOriginalCauseSurvivesARollbackThatFails(): void
    {
        $refusal = new DomainException(self::CAUSE_MESSAGE);
        $caught = null;

        try {
            $this->transaction()->run(
                acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                onTimeout: $this->unexpectedTimeout(...),
                operation: function () use ($refusal): never {
                    $this->terminateTheSession();

                    throw $refusal;
                },
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertSame($refusal, $caught, \sprintf("La cause d'origine a été masquée par l'échec du ROLLBACK : %s.", get_debug_type($caught)));
        $this->assertTheFailedRollbackIsLoggedWithoutItsCause(DomainException::class, nested: false);
        $this->assertTheDeadSessionWasReplaced();
    }

    /**
     * Même garantie après un COMMIT que DBAL refuse lui-même
     * (`setRollbackOnly()`) : le ROLLBACK qui le suit échoue sur la session
     * coupée, et c'est le refus du COMMIT qui remonte.
     */
    public function testACommitRefusalSurvivesTheRollbackThatFollowsIt(): void
    {
        $caught = null;

        try {
            $this->transaction()->run(
                acquire: fn (): mixed => $this->connection->executeQuery(self::LOCK_SQL),
                onTimeout: $this->unexpectedTimeout(...),
                operation: function (): void {
                    $this->terminateTheSession();
                    $this->connection->setRollbackOnly();
                },
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(CommitFailedRollbackOnly::class, $caught, \sprintf("Le refus du COMMIT a été masqué par l'échec du ROLLBACK : %s.", get_debug_type($caught)));
        $this->assertTheFailedRollbackIsLoggedWithoutItsCause(CommitFailedRollbackOnly::class, nested: false);
        $this->assertTheDeadSessionWasReplaced();
    }

    /**
     * Imbriqué, le ROLLBACK refusé est celui du point de sauvegarde, et DBAL
     * garde alors son niveau : relancer la seule cause laisserait l'appelant
     * valider une transaction que la session fermée a emportée. Une exception
     * nommée le lui dit, avec la cause en `previous`.
     */
    public function testANestedRollbackThatFailsTellsTheCallerItsTransactionIsLost(): void
    {
        $refusal = new DomainException(self::CAUSE_MESSAGE);
        $this->connection->beginTransaction();
        $caught = null;

        try {
            $this->transaction()->run(
                acquire: static fn (): null => null,
                onTimeout: $this->unexpectedTimeout(...),
                operation: function () use ($refusal): never {
                    $this->terminateTheSession();

                    throw $refusal;
                },
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(LockedTransactionRollbackFailedException::class, $caught, \sprintf("L'appelant n'a pas appris que sa transaction est perdue : %s.", get_debug_type($caught)));
        self::assertSame($refusal, $caught->getPrevious(), "La cause d'origine n'est pas en previous.");
        self::assertStringNotContainsString(self::CAUSE_MESSAGE, $caught->getMessage());
        $this->assertTheFailedRollbackIsLoggedWithoutItsCause(DomainException::class, nested: true);
        $this->assertTheDeadSessionWasReplaced();
    }

    /**
     * Seul un refus de la base est rattrapé autour du ROLLBACK : une erreur
     * de programmation — l'opération a validé elle-même la transaction du
     * squelette — remonte comme avant, au lieu de se cacher derrière le
     * refus métier qui la suit.
     */
    public function testAProgrammingErrorOfTheRollbackStillSurfaces(): void
    {
        $caught = null;

        try {
            $this->transaction()->run(
                acquire: static fn (): null => null,
                onTimeout: $this->unexpectedTimeout(...),
                operation: function (): never {
                    $this->connection->commit();

                    throw new DomainException('refus après un COMMIT indu');
                },
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(NoActiveTransaction::class, $caught, \sprintf("L'erreur de programmation a été masquée : %s.", get_debug_type($caught)));
        self::assertSame([], $this->logs->getRecords());
    }

    /**
     * Imbriqué dans une transaction déjà ouverte, le squelette n'annule que
     * la sienne (son point de sauvegarde) : celle de l'appelant reste à lui.
     */
    public function testInsideAnOuterTransactionOnlyItsOwnLevelIsRolledBack(): void
    {
        $this->connection->beginTransaction();

        try {
            try {
                $this->transaction()->run(
                    acquire: static fn (): null => null,
                    onTimeout: $this->unexpectedTimeout(...),
                    operation: $this->connection->setRollbackOnly(...),
                );
            } catch (Throwable) {
            }

            self::assertSame(1, $this->connection->getTransactionNestingLevel(), "La transaction de l'appelant a été touchée.");
        } finally {
            $this->connection->rollBack();
        }
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

    /**
     * La session dont le ROLLBACK a échoué a été fermée par le squelette, et
     * DBAL en rouvre une neuve, hors de toute transaction.
     */
    private function assertTheDeadSessionWasReplaced(): void
    {
        self::assertFalse($this->connection->isConnected(), 'La session morte a été gardée.');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, $this->connection->fetchOne('SELECT 1'));
    }

    private function transaction(string $lockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT): PostgresLockedTransaction
    {
        return new PostgresLockedTransaction($this->connection, new Logger('test', [$this->logs]), $lockTimeout);
    }

    /**
     * Coupe, depuis la sonde, la session de la connexion testée, et attend
     * jusqu'à 2 s qu'elle soit partie : PostgreSQL y répond en `57P01`.
     */
    private function terminateTheSession(): void
    {
        $pid = $this->connection->fetchOne('SELECT pg_backend_pid()');

        self::assertTrue($this->probe->fetchOne('SELECT pg_terminate_backend(?, 2000)', [$pid]), 'La session testée ne s\'est pas terminée.');
    }

    /**
     * Une seule ligne `error`, qui nomme la classe de la cause et celle de
     * l'échec du ROLLBACK, et rien du message de la cause.
     *
     * @param class-string<Throwable> $cause
     */
    private function assertTheFailedRollbackIsLoggedWithoutItsCause(string $cause, bool $nested): void
    {
        $records = $this->logs->getRecords();

        self::assertCount(1, $records, "L'échec du ROLLBACK n'a pas été journalisé, ou l'a été plusieurs fois.");
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame($cause, $records[0]->context['cause'] ?? null);
        self::assertSame($nested, $records[0]->context['nested'] ?? null);
        self::assertIsString($records[0]->context['rollbackFailure'] ?? null);
        self::assertTrue(is_a($records[0]->context['rollbackFailure'], Throwable::class, true), "L'échec du ROLLBACK n'est pas nommé par sa classe.");
        self::assertStringNotContainsString(self::CAUSE_MESSAGE, $records[0]->message.json_encode($records[0]->context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
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
