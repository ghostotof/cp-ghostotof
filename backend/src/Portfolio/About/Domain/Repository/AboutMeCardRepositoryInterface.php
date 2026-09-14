<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Repository;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (AboutMeCardAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans Infrastructure\Doctrine\AboutMeCardRepository.
 */
interface AboutMeCardRepositoryInterface
{
    public function findOneById(Uuid $id): ?AboutMeCard;

    /**
     * @return list<AboutMeCard> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<AboutMeCard> triées par position ASC ; utilisé par
     *                           BackofficeAboutMeCardProvider pour le filtre combiné
     *                           ?locale=&category= (AboutContentProvider, lui, ne filtre
     *                           que par locale puis range les cartes par catégorie en PHP)
     */
    public function findByLocaleAndCategory(Locale $locale, AboutMeCardCategory $category): array;

    /**
     * @return list<AboutMeCard> triées par locale puis position ASC
     */
    public function findByCategory(AboutMeCardCategory $category): array;

    /**
     * @return list<AboutMeCard> triées par locale puis catégorie puis position ASC
     */
    public function findAll(): array;

    /**
     * Spec 0004 D1 : les versions d'un même contenu, toutes langues
     * confondues. L'index unique (translation_group, locale) garantit au plus
     * une entrée par langue, donc au plus `count(Locale::cases())` résultats.
     *
     * @return list<AboutMeCard> triées par locale ASC
     */
    public function findByTranslationGroup(Uuid $translationGroup): array;

    public function save(AboutMeCard $card): void;

    /**
     * Spec 0004 B3 : écrit plusieurs entités en une seule transaction — le
     * besoin de `OrderAssigner::assign()`, qui déplace potentiellement tout un
     * périmètre en un seul appel. `save()` flushe à chaque entité, donc autant
     * de transactions que d'entités ; cette méthode n'en ouvre qu'une.
     *
     * @param list<AboutMeCard> $cards
     */
    public function saveAll(array $cards): void;

    public function remove(AboutMeCard $card): void;
}
