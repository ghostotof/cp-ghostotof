<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/**
 * Pourquoi {@see VanishedEntities} retient une instance (issues #548, #557) :
 * le refus de sa re-sauvegarde nomme la cause, que le journal distingue.
 */
enum VanishedEntityCause
{
    /**
     * Sa ligne a disparu entre son chargement et le verrou qui l'a constaté,
     * et ce verrou l'a détachée (#548).
     */
    case VanishedUnderLock;

    /**
     * Ce même EntityManager l'a supprimée — `remove()` puis `flush()` (#557).
     */
    case RemovedByThisManager;
}
