<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Repository;

use App\Portfolio\Watch\Domain\Entity\WatchedProduct;

/**
 * Abstraction (DIP) dont dépend la couche Application : elle ne connaît jamais
 * Doctrine directement.
 */
interface WatchedProductRepositoryInterface
{
    public function findOneById(int $id): ?WatchedProduct;

    public function findOneBySlug(string $slug): ?WatchedProduct;

    /**
     * @return list<WatchedProduct> triés par position ASC
     */
    public function findAllOrdered(): array;

    public function save(WatchedProduct $product): void;

    public function remove(WatchedProduct $product): void;
}
