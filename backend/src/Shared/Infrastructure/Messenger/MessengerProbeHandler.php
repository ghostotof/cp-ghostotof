<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Traite le message témoin du smoke préprod (issue #470) dans le worker : pose
 * une marque sous son identifiant, que {@see CacheMarkedMessengerProbe} guette.
 * Aucun autre effet : ni mail, ni donnée métier.
 */
#[AsMessageHandler]
final readonly class MessengerProbeHandler
{
    /**
     * Une marque qu'aucune sonde ne lit (sonde sortie au délai) expire, et
     * `cache:pool:prune` la purge.
     */
    public const int MARK_LIFETIME_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(MessengerProbeMessage $message): void
    {
        $item = $this->cache->getItem(CacheMarkedMessengerProbe::markerKey($message->probeId));

        // L'adaptateur DBAL avale une écriture qui échoue et rend false
        // (constat A1 de l'audit du 2026-09-16) : sans cette exception, le
        // témoin serait acquitté sans marque, et le smoke reprocherait au
        // worker ce qui est une panne de base. Messenger réessaie.
        if (!$this->cache->save($item->set(true)->expiresAfter(self::MARK_LIFETIME_SECONDS))) {
            throw ProbeMarkNotSavedException::forProbe();
        }
    }
}
