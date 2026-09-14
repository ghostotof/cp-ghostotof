<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Domain\Repository;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (ContributionAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans Infrastructure\Doctrine.
 */
interface ContributionRepositoryInterface
{
    public function findOneById(Uuid $id): ?Contribution;

    /**
     * @return list<Contribution> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<Contribution> triées par locale puis position ASC
     */
    public function findAll(): array;

    /**
     * Spec 0004 D1 : les versions d'un même contenu, toutes langues
     * confondues. L'index unique (translation_group, locale) garantit au plus
     * une entrée par langue, donc au plus `count(Locale::cases())` résultats.
     *
     * @return list<Contribution> triées par locale ASC
     */
    public function findByTranslationGroup(Uuid $translationGroup): array;

    public function save(Contribution $contribution): void;

    /**
     * Spec 0004 B3 : écrit plusieurs entités en une seule transaction — le
     * besoin de `OrderAssigner::assign()`, qui déplace potentiellement tout un
     * périmètre en un seul appel. `save()` flushe à chaque entité, donc autant
     * de transactions que d'entités ; cette méthode n'en ouvre qu'une.
     *
     * @param list<Contribution> $contributions
     */
    public function saveAll(array $contributions): void;

    public function remove(Contribution $contribution): void;
}
