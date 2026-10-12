<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\WorkerRestartSignalInterface;
use App\Shared\Application\WorkerRestartSignalNotSavedException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Le signal d'arrêt des workers, écrit puis relu (issue #528).
 *
 * Même écriture que `messenger:stop-workers` de Symfony, pour que
 * {@see StopWorkerOnRestartSignalListener} la lise comme la sienne : le même
 * pool (`cache.messenger.restart_workers_signal`, celui que FrameworkBundle
 * passe au listener et à la commande), la même clé (la constante du listener)
 * et le même format (un horodatage en secondes flottantes, que le listener
 * compare par `<` à l'heure de démarrage du worker).
 *
 * Ce qu'elle ajoute : la commande de Symfony ignore le retour de `save()`, et
 * sur le cache en base une écriture refusée y devient un `false` silencieux —
 * la famille d'ADR 0005. Ici, un refus lève une exception nommée, et une
 * écriture acceptée n'est crue que si sa valeur se relit **à l'identique** :
 * par `getItem()`, jamais `hasItem()`, qui ne fait sur DBAL qu'un `SELECT 1`
 * sans le marshaller qui vérifie le MAC (#468), et contre la valeur écrite,
 * pas un simple `isHit()`, que le signal d'un arrêt précédent satisferait.
 */
final readonly class CacheWorkerRestartSignal implements WorkerRestartSignalInterface
{
    public function __construct(
        #[Autowire(service: 'cache.messenger.restart_workers_signal')]
        private CacheItemPoolInterface $restartSignalPool,
        private ClockInterface $clock,
    ) {
    }

    public function send(): void
    {
        // L'expression du Worker de Messenger pour « maintenant » : des
        // secondes à la microseconde, en flottant, comme `microtime(true)`.
        $requestedAt = (float) $this->clock->now()->format('U.u');

        // Comme la commande de Symfony : `getItem()` lit d'abord l'ancienne
        // ligne. Après une rotation d'`APP_SECRET`, son MAC est refusé, d'où un
        // `warning` « Failed to fetch » attendu du canal `cache`, sans effet.
        $item = $this->restartSignalPool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        $item->set($requestedAt);
        if (!$this->restartSignalPool->save($item)) {
            throw WorkerRestartSignalNotSavedException::refusedByTheStorage();
        }

        // `getItem()` relit la base : aucun cache mémoire devant l'adaptateur.
        // Une relecture en échec passager se lit comme une absence (l'adaptateur
        // rattrape l'erreur) : `notReadBack()` le dit sans affirmer l'échec.
        $readBack = $this->restartSignalPool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        if (!$readBack->isHit() || $requestedAt !== $readBack->get()) {
            throw WorkerRestartSignalNotSavedException::notReadBack();
        }
    }
}
