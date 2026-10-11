<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Infrastructure\Messenger\CacheMarkedMessengerProbe;
use App\Shared\Infrastructure\Messenger\MessengerProbeHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Le worker traite le message témoin (issue #470) : il pose une marque, et
 * rien d'autre — aucun mail, aucune donnée métier.
 */
final class MessengerProbeHandlerTest extends TestCase
{
    public function testItMarksTheNonceItReceives(): void
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

    /** Le handler ne reçoit que le cache : il ne peut envoyer aucun mail. */
    public function testItDependsOnTheCacheAlone(): void
    {
        $constructor = new ReflectionClass(MessengerProbeHandler::class)->getConstructor();

        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters());
    }
}
