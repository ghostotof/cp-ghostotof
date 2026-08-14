<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Repository;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (QualityTraitAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans
 * Infrastructure\Doctrine\QualityTraitRepository.
 */
interface QualityTraitRepositoryInterface
{
    public function findOneById(int $id): ?QualityTraitEntity;

    /**
     * @return list<QualityTraitEntity> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<QualityTraitEntity> triées par locale puis position ASC
     */
    public function findAll(): array;

    public function save(QualityTraitEntity $trait): void;

    public function remove(QualityTraitEntity $trait): void;
}
