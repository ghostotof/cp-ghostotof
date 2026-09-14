<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Domain\Repository;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) : la couche Application ne connaît jamais Doctrine
 * directement. L'implémentation concrète vit dans Infrastructure\Doctrine.
 */
interface CaseStudyRepositoryInterface
{
    public function findOneById(Uuid $id): ?CaseStudy;

    /**
     * @return list<CaseStudy> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<CaseStudy> triées par locale puis position ASC
     */
    public function findAll(): array;

    /**
     * Spec 0004 D1 : les versions d'un même contenu, toutes langues
     * confondues. L'index unique (translation_group, locale) garantit au plus
     * une entrée par langue, donc au plus `count(Locale::cases())` résultats.
     *
     * @return list<CaseStudy> triées par locale ASC
     */
    public function findByTranslationGroup(Uuid $translationGroup): array;

    public function save(CaseStudy $caseStudy): void;

    /**
     * Spec 0004 B3 : écrit plusieurs entités en une seule transaction — le
     * besoin de `OrderAssigner::assign()`, qui déplace potentiellement tout un
     * périmètre en un seul appel. `save()` flushe à chaque entité, donc autant
     * de transactions que d'entités ; cette méthode n'en ouvre qu'une.
     *
     * @param list<CaseStudy> $caseStudys
     */
    public function saveAll(array $caseStudys): void;

    public function remove(CaseStudy $caseStudy): void;
}
