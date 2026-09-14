<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Repository;

use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application : elle ne connaît jamais
 * Doctrine directement.
 */
interface WatchedProductRepositoryInterface
{
    public function findOneById(Uuid $id): ?WatchedProduct;

    public function findOneBySlug(string $slug): ?WatchedProduct;

    /**
     * @return list<WatchedProduct> triés par position ASC
     */
    public function findAllOrdered(): array;

    public function save(WatchedProduct $product): void;

    /**
     * Spec 0004 B3 : écrit plusieurs entités en une seule transaction — le
     * besoin de `OrderAssigner::assign()`, qui déplace potentiellement tout le
     * catalogue en un seul appel. `save()` flushe à chaque entité, donc autant
     * de transactions que d'entités ; cette méthode n'en ouvre qu'une.
     *
     * @param list<WatchedProduct> $products
     */
    public function saveAll(array $products): void;

    public function remove(WatchedProduct $product): void;
}
