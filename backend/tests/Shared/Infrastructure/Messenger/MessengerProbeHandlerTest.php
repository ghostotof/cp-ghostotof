<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Infrastructure\Messenger\CacheMarkedMessengerProbe;
use App\Shared\Infrastructure\Messenger\MessengerProbeHandler;
use App\Shared\Infrastructure\Messenger\ProbeMarkNotSavedException;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Le worker traite le message témoin (issue #470) : il pose une marque, et
 * rien d'autre — aucun mail, aucune donnée métier.
 */
final class MessengerProbeHandlerTest extends TestCase
{
    public function testItMarksTheProbeIdItReceives(): void
    {
        $cache = new ArrayAdapter();

        new MessengerProbeHandler($cache)(new MessengerProbeMessage(str_repeat('a', 32)));

        self::assertTrue($cache->hasItem(CacheMarkedMessengerProbe::markerKey(str_repeat('a', 32))));
    }

    /**
     * Une marque qu'aucune sonde ne vient lire (sonde déjà sortie au délai)
     * finit par expirer : `cache:pool:prune` la purge.
     */
    public function testTheMarkExpires(): void
    {
        // Partie de maintenant : CacheItem::expiresAfter() calcule l'échéance
        // sur l'heure réelle, l'adaptateur la compare à son horloge.
        $clock = new MockClock();
        $cache = new ArrayAdapter(clock: $clock);
        $key = CacheMarkedMessengerProbe::markerKey(str_repeat('b', 32));
        new MessengerProbeHandler($cache)(new MessengerProbeMessage(str_repeat('b', 32)));

        $clock->sleep(MessengerProbeHandler::MARK_LIFETIME_SECONDS - 1);
        self::assertTrue($cache->hasItem($key));

        $clock->sleep(2);
        self::assertFalse($cache->hasItem($key));
    }

    /**
     * L'adaptateur DBAL avale une écriture qui échoue (`save()` rend false,
     * constat A1 de l'audit du 2026-09-16) : le worker acquitterait le témoin
     * sans marque, la sonde échouerait, et la préprod repartirait en arrière à
     * tort. L'exception laisse Messenger réessayer.
     */
    public function testAMarkThatCannotBeSavedFailsTheMessage(): void
    {
        $cache = self::createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturnCallback(static fn (string $key): CacheItemInterface => new ArrayAdapter()->getItem($key));
        $cache->method('save')->willReturn(false);

        $this->expectException(ProbeMarkNotSavedException::class);

        new MessengerProbeHandler($cache)(new MessengerProbeMessage(str_repeat('d', 32)));
    }

    /** Le handler ne reçoit que le cache : il ne peut envoyer aucun mail. */
    public function testItDependsOnTheCacheAlone(): void
    {
        $constructor = new ReflectionClass(MessengerProbeHandler::class)->getConstructor();

        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters());
    }
}
