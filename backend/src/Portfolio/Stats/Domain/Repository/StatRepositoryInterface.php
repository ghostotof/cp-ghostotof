<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Domain\Repository;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Domain\Entity\Stat;

/**
 * Abstraction (DIP) dont dépend la couche Application (StatAdministrator) :
 * elle ne connaît jamais Doctrine directement. L'implémentation concrète vit
 * dans Infrastructure\Doctrine\StatRepository.
 */
interface StatRepositoryInterface
{
    public function findOneById(int $id): ?Stat;

    /**
     * @return list<Stat> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<Stat> triées par locale puis position ASC
     */
    public function findAll(): array;

    public function save(Stat $stat): void;

    public function remove(Stat $stat): void;
}
