<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Repository;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (ExperienceTechnologyRegistrar) : elle ne connaît jamais Doctrine
 * directement. L'implémentation concrète vit dans
 * Infrastructure\Doctrine\ExperienceTechnologyRepository.
 */
interface ExperienceTechnologyRepositoryInterface
{
    public function findOneByName(string $name): ?ExperienceTechnology;

    public function findOneById(int $id): ?ExperienceTechnology;

    public function save(ExperienceTechnology $technology): void;

    public function remove(ExperienceTechnology $technology): void;

    /**
     * @return list<ExperienceTechnology>
     */
    public function findAllOrderedByYearsDesc(): array;
}
