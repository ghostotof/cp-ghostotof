<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Repository;

use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;

/**
 * Abstraction (DIP) dont dépend la couche Application : elle ne connaît jamais
 * Doctrine directement.
 */
interface WatchSnapshotRepositoryInterface
{
    /**
     * Le snapshot courant du type demandé, ou null si aucun rafraîchissement
     * n'a jamais abouti — cas « never_refreshed » de la spécification, qui doit
     * rester un état affichable et non une erreur.
     */
    public function findOneByType(WatchSnapshotType $type): ?WatchSnapshot;

    public function save(WatchSnapshot $snapshot): void;
}
