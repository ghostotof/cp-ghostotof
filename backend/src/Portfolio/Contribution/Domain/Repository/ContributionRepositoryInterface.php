<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Domain\Repository;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (ContributionAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans Infrastructure\Doctrine.
 */
interface ContributionRepositoryInterface
{
    public function findOneById(int $id): ?Contribution;

    /**
     * @return list<Contribution> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<Contribution> triées par locale puis position ASC
     */
    public function findAll(): array;

    public function save(Contribution $contribution): void;

    public function remove(Contribution $contribution): void;
}
