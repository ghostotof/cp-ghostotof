<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Repository;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (QualityPrincipleAdministrator) : elle ne connaît jamais Doctrine
 * directement. L'implémentation concrète vit dans
 * Infrastructure\Doctrine\QualityPrincipleRepository.
 */
interface QualityPrincipleRepositoryInterface
{
    public function findOneById(int $id): ?QualityPrinciple;

    /**
     * @return list<QualityPrinciple> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<QualityPrinciple> triées par locale puis position ASC
     */
    public function findAll(): array;

    public function save(QualityPrinciple $principle): void;

    public function remove(QualityPrinciple $principle): void;
}
