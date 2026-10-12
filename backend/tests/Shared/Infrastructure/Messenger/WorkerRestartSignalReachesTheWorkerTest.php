<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

/**
 * `app:messenger:stop-workers` (issue #528), par le conteneur et la vraie
 * table `cache_items`.
 *
 * Une commande à nous ne vaut que si le worker s'arrête sur ce qu'elle écrit :
 * même pool, même clé, même format de valeur que la commande de Symfony. Le
 * premier test le prouve sans rien supposer de ces trois points : il fait
 * tourner un vrai {@see Worker} avec le listener du conteneur — l'instance que
 * reçoit `messenger:consume` — et compte les tours. Écrit dans `cache.app`
 * plutôt que dans `cache.messenger.restart_workers_signal` (un autre espace de
 * noms de la même table), sous une autre clé, ou en chaîne de date, le signal
 * ne l'arrêterait pas au premier tour.
 *
 * Les deux derniers reproduisent l'écriture refusée de l'issue, une session
 * PostgreSQL en `default_transaction_read_only` (mesurée en dev pour #521 par
 * `PGOPTIONS`) : la nôtre échoue, celle de Symfony répond toujours `[OK]`.
 */
final class WorkerRestartSignalReachesTheWorkerTest extends KernelTestCase
{
    /** Le pool que lisent le listener et `messenger:stop-workers` (FrameworkBundle). */
    private const string SIGNAL_POOL = 'cache.messenger.restart_workers_signal';

    /** Délai laissé au worker témoin, en secondes : sans signal, il s'arrête là. */
    private const float WORKER_TIME_LIMIT = 0.5;

    /** Pause entre deux tours à vide, en microsecondes (option `sleep` du worker). */
    private const int WORKER_SLEEP = 10_000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->forgetSignal();
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement('SET SESSION default_transaction_read_only = off');
        $this->forgetSignal();
        parent::tearDown();
    }

    public function testTheWorkerStopsOnTheSignalOurCommandWrites(): void
    {
        self::assertSame(1, $this->roundsBeforeTheWorkerStops('app:messenger:stop-workers'));
    }

    /**
     * Le témoin du précédent : sans signal, le même worker tourne jusqu'à sa
     * limite de temps. Sans lui, un worker qui s'arrêterait pour une autre
     * raison au premier tour ferait passer le test pour rien.
     */
    public function testWithoutASignalTheWorkerRunsUntilItsTimeLimit(): void
    {
        self::assertGreaterThan(1, $this->roundsBeforeTheWorkerStops(null));
    }

    /**
     * La base refuse l'écriture : la commande échoue, alors même qu'un signal
     * d'un arrêt précédent, valide, se relit encore — seule la valeur écrite
     * par cet appel compte.
     */
    public function testOurCommandFailsWhenTheDatabaseRefusesTheWrite(): void
    {
        self::assertSame(Command::SUCCESS, $this->command('app:messenger:stop-workers')->execute([]));
        $this->connection()->executeStatement('SET SESSION default_transaction_read_only = on');

        $tester = $this->command('app:messenger:stop-workers');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('refusé', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    /**
     * Le constat de l'issue, épinglé : si ce test rougit, Symfony a corrigé sa
     * commande, et le Job pourra y revenir — à décider en relisant #528.
     */
    public function testSymfonysOwnCommandStillReportsSuccessWhenTheWriteIsRefused(): void
    {
        $this->connection()->executeStatement('SET SESSION default_transaction_read_only = on');

        $tester = $this->command('messenger:stop-workers');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('[OK]', $tester->getDisplay());
        $this->connection()->executeStatement('SET SESSION default_transaction_read_only = off');
        self::assertFalse($this->signalPool()->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY)->isHit());
    }

    /**
     * Fait tourner un worker sans transport (chaque tour est un tour à vide)
     * avec le listener du conteneur, lance la commande au premier tour, avant
     * que le listener ne lise le signal, et compte les tours.
     */
    private function roundsBeforeTheWorkerStops(?string $command): int
    {
        // L'instance du conteneur, typée par phpstan-symfony : celle du worker.
        $listener = self::getContainer()->get('messenger.listener.stop_worker_on_restart_signal_listener');

        $rounds = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);
        // Priorité au-dessus du listener (0) : la commande écrit, puis il lit.
        $dispatcher->addListener(WorkerRunningEvent::class, function () use (&$rounds, $command): void {
            ++$rounds;
            if (1 === $rounds && null !== $command) {
                self::assertSame(Command::SUCCESS, $this->command($command)->execute([]));
            }
        }, 10);

        new Worker([], new MessageBus(), $dispatcher)->run(['sleep' => self::WORKER_SLEEP, 'time_limit' => self::WORKER_TIME_LIMIT]);

        return $rounds;
    }

    private function command(string $name): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester(new Application(self::$kernel)->find($name));
    }

    private function signalPool(): CacheItemPoolInterface
    {
        return self::getContainer()->get(self::SIGNAL_POOL);
    }

    private function forgetSignal(): void
    {
        $this->signalPool()->deleteItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('database_connection');
    }
}
