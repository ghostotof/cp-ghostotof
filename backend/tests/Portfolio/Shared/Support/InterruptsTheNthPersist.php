<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Support;

use Doctrine\ORM\Event\PrePersistEventArgs;

/**
 * Écouteur `prePersist` de test : laisse passer les N − 1 premiers
 * `persist()` et lève {@see PersistInterruptedException} au N-ième — la panne
 * simulée d'un `app:*:seed` à mi-parcours (issue #567). Chaque création d'un
 * `Administrator` est suivie de son `flush()` : au N-ième persist, N − 1
 * lignes ont donc déjà été écrites par la commande.
 *
 * `prePersist`, et non un événement de flush : `ChildProcessHarnessTest`
 * réserve `preFlush`/`onFlush`/`postFlush` à `FlushInterleaving`.
 */
final class InterruptsTheNthPersist
{
    private int $seen = 0;

    public function __construct(private readonly int $ordinal)
    {
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        ++$this->seen;

        if ($this->seen === $this->ordinal) {
            throw PersistInterruptedException::atPersist($this->ordinal, $event->getObject()::class);
        }
    }
}
