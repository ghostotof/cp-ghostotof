<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Application\MessengerRoundTripProbeInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sonde du trajet API → worker (issue #470) : met en file un
 * {@see MessengerProbeMessage} portant un identifiant aléatoire, puis guette la
 * marque que {@see MessengerProbeHandler} pose sous cet identifiant.
 *
 * La marque passe par `cache.app`, en base (ADR 0005) : le seul stockage que
 * le pod du Job et celui du worker partagent sans rien ajouter. Elle est
 * signée comme toute valeur de ce pool (issue #468). L'identifiant, propre à
 * chaque sonde, empêche la marque d'une sonde précédente de faire réussir la
 * suivante ; la marque lue est retirée.
 */
final readonly class CacheMarkedMessengerProbe implements MessengerRoundTripProbeInterface
{
    private const float POLL_INTERVAL_SECONDS = 0.5;

    public function __construct(
        private MessageBusInterface $bus,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public static function markerKey(string $probeId): string
    {
        return 'messenger_probe.'.$probeId;
    }

    public function probe(int $timeoutSeconds): bool
    {
        $probeId = bin2hex(random_bytes(16));
        $key = self::markerKey($probeId);
        $deadline = $this->clock->now()->getTimestamp() + $timeoutSeconds;

        $this->bus->dispatch(new MessengerProbeMessage($probeId));

        while ($this->clock->now()->getTimestamp() < $deadline) {
            if ($this->cache->hasItem($key)) {
                $this->cache->deleteItem($key);

                return true;
            }
            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }

        return $this->cache->hasItem($key) && $this->cache->deleteItem($key);
    }
}
