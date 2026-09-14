<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Repository;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (QualityTraitAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans
 * Infrastructure\Doctrine\QualityTraitRepository.
 */
interface QualityTraitRepositoryInterface
{
    public function findOneById(Uuid $id): ?QualityTraitEntity;

    /**
     * @return list<QualityTraitEntity> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<QualityTraitEntity> triées par locale puis position ASC
     */
    public function findAll(): array;

    /**
     * Spec 0004 D1 : les versions d'un même contenu, toutes langues
     * confondues. L'index unique (translation_group, locale) garantit au plus
     * une entrée par langue, donc au plus `count(Locale::cases())` résultats.
     *
     * @return list<QualityTraitEntity> triées par locale ASC
     */
    public function findByTranslationGroup(Uuid $translationGroup): array;

    public function save(QualityTraitEntity $trait): void;

    /**
     * Spec 0004 B3 : écrit plusieurs entités en une seule transaction — le
     * besoin de `OrderAssigner::assign()`, qui déplace potentiellement tout un
     * périmètre en un seul appel. `save()` flushe à chaque entité, donc autant
     * de transactions que d'entités ; cette méthode n'en ouvre qu'une.
     *
     * @param list<QualityTraitEntity> $traits
     */
    public function saveAll(array $traits): void;

    public function remove(QualityTraitEntity $trait): void;
}
