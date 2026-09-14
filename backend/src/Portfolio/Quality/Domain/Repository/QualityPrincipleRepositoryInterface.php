<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Repository;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (QualityPrincipleAdministrator) : elle ne connaît jamais Doctrine
 * directement. L'implémentation concrète vit dans
 * Infrastructure\Doctrine\QualityPrincipleRepository.
 */
interface QualityPrincipleRepositoryInterface
{
    public function findOneById(Uuid $id): ?QualityPrinciple;

    /**
     * @return list<QualityPrinciple> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<QualityPrinciple> triées par locale puis position ASC
     */
    public function findAll(): array;

    /**
     * Spec 0004 D1 : les versions d'un même contenu, toutes langues
     * confondues. L'index unique (translation_group, locale) garantit au plus
     * une entrée par langue, donc au plus `count(Locale::cases())` résultats.
     *
     * @return list<QualityPrinciple> triées par locale ASC
     */
    public function findByTranslationGroup(Uuid $translationGroup): array;

    public function save(QualityPrinciple $principle): void;

    public function remove(QualityPrinciple $principle): void;
}
