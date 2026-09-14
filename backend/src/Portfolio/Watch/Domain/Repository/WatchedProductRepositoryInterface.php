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

    public function remove(WatchedProduct $product): void;
}
