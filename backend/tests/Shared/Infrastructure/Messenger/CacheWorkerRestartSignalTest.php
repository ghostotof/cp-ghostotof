<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Application\WorkerRestartSignalNotSavedException;
use App\Shared\Infrastructure\Messenger\CacheWorkerRestartSignal;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Le signal d'arrêt des workers (issue #528) : écrit sous la clé et au format
 * que lit {@see StopWorkerOnRestartSignalListener}, puis relu par sa valeur.
 *
 * `messenger:stop-workers` de Symfony ignore le retour de `save()` : sur le
 * cache en base, une écriture refusée y devient un `[OK]`. Ces tests prouvent
 * que chaque façon de ne pas enregistrer le signal lève une exception nommée.
 * WorkerRestartSignalReachesTheWorkerTest prouve, sur la vraie base, que le
 * vrai listener s'arrête sur la valeur écrite.
 */
final class CacheWorkerRestartSignalTest extends TestCase
{
    private const string NOW = '2026-10-12 14:30:00.250000';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
    }

    /**
     * La valeur est un horodatage en secondes flottantes, comme celui que
     * Symfony écrit (`microtime(true)`) : le listener la compare par `<` à
     * l'heure de démarrage du worker.
     */
    public function testItWritesTheCurrentTimestampUnderTheListenersKey(): void
    {
        $pool = new ArrayAdapter();

        new CacheWorkerRestartSignal($pool, $this->clock)->send();

        $item = $pool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        self::assertTrue($item->isHit());
        self::assertSame((float) $this->clock->now()->format('U.u'), $item->get());
    }

    /** Un signal déjà présent est remplacé : l'arrêt vaut pour les workers démarrés avant. */
    public function testItReplacesAnEarlierSignal(): void
    {
        $pool = new ArrayAdapter();
        $signal = new CacheWorkerRestartSignal($pool, $this->clock);
        $signal->send();
        $this->clock->sleep(30);

        $signal->send();

        self::assertSame(
            (float) $this->clock->now()->format('U.u'),
            $pool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY)->get(),
        );
    }

    /** Le cas de l'issue : la base refuse l'écriture, `save()` rend false. */
    public function testAWriteTheCacheRefusesRaisesANamedFailure(): void
    {
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(static fn (string $key): CacheItemInterface => new ArrayAdapter()->getItem($key));
        $pool->method('save')->willReturn(false);

        $this->expectException(WorkerRestartSignalNotSavedException::class);
        $this->expectExceptionMessage('refusé');

        new CacheWorkerRestartSignal($pool, $this->clock)->send();
    }

    /**
     * Un `save()` à true ne suffit pas : seule la valeur relue prouve que le
     * worker la lira. Ici, rien ne se relit — une ligne au MAC refusé, après
     * une rotation d'`APP_SECRET`, se lit de même comme une absence (#468).
     */
    public function testASignalThatCannotBeReadBackRaisesANamedFailure(): void
    {
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(static fn (string $key): CacheItemInterface => new ArrayAdapter()->getItem($key));
        $pool->method('save')->willReturn(true);

        $this->expectException(WorkerRestartSignalNotSavedException::class);
        $this->expectExceptionMessage('relu');

        new CacheWorkerRestartSignal($pool, $this->clock)->send();
    }

    /**
     * Le piège d'un `isHit()` seul : le signal d'un arrêt précédent se relit
     * très bien. Seule la valeur écrite par cet appel compte.
     */
    public function testAnEarlierSignalReadBackInsteadOfThisOneRaisesANamedFailure(): void
    {
        $earlier = new ArrayAdapter();
        $earlierItem = $earlier->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        $earlierItem->set((float) $this->clock->now()->format('U.u') - 3600.0);
        $earlier->save($earlierItem);

        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(static fn (string $key): CacheItemInterface => $earlier->getItem($key));
        $pool->method('save')->willReturn(true);

        $this->expectException(WorkerRestartSignalNotSavedException::class);
        $this->expectExceptionMessage('relu');

        new CacheWorkerRestartSignal($pool, $this->clock)->send();
    }
}
