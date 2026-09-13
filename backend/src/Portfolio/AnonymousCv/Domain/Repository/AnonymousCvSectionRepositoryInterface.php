<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Domain\Repository;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) : la couche Application ne connaît jamais Doctrine
 * directement. L'implémentation concrète vit dans Infrastructure\Doctrine.
 */
interface AnonymousCvSectionRepositoryInterface
{
    public function findOneById(int $id): ?AnonymousCvSection;

    /**
     * @return list<AnonymousCvSection> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<AnonymousCvSection> triées par locale puis position ASC
     */
    public function findAll(): array;

    public function save(AnonymousCvSection $section): void;

    public function remove(AnonymousCvSection $section): void;
}
