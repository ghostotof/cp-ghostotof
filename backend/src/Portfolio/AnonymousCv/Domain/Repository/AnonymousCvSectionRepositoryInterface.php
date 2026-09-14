<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Domain\Repository;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) : la couche Application ne connaît jamais Doctrine
 * directement. L'implémentation concrète vit dans Infrastructure\Doctrine.
 */
interface AnonymousCvSectionRepositoryInterface
{
    public function findOneById(Uuid $id): ?AnonymousCvSection;

    /**
     * @return list<AnonymousCvSection> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<AnonymousCvSection> triées par locale puis position ASC
     */
    public function findAll(): array;

    /**
     * Spec 0004 D1 : les versions d'un même contenu, toutes langues
     * confondues. L'index unique (translation_group, locale) garantit au plus
     * une entrée par langue, donc au plus `count(Locale::cases())` résultats.
     *
     * @return list<AnonymousCvSection> triées par locale ASC
     */
    public function findByTranslationGroup(Uuid $translationGroup): array;

    public function save(AnonymousCvSection $section): void;

    public function remove(AnonymousCvSection $section): void;
}
