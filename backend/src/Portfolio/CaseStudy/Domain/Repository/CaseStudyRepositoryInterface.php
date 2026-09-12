<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Domain\Repository;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) : la couche Application ne connaît jamais Doctrine
 * directement. L'implémentation concrète vit dans Infrastructure\Doctrine.
 */
interface CaseStudyRepositoryInterface
{
    public function findOneById(int $id): ?CaseStudy;

    /**
     * @return list<CaseStudy> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<CaseStudy> triées par locale puis position ASC
     */
    public function findAll(): array;

    public function save(CaseStudy $caseStudy): void;

    public function remove(CaseStudy $caseStudy): void;
}
