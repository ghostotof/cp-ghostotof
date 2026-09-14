<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Repository;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (AboutSiteCardAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans Infrastructure\Doctrine\AboutSiteCardRepository.
 */
interface AboutSiteCardRepositoryInterface
{
    public function findOneById(Uuid $id): ?AboutSiteCard;

    /**
     * @return list<AboutSiteCard> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<AboutSiteCard> triées par locale puis position ASC
     */
    public function findAll(): array;

    /**
     * Spec 0004 D1 : les versions d'un même contenu, toutes langues
     * confondues. L'index unique (translation_group, locale) garantit au plus
     * une entrée par langue, donc au plus `count(Locale::cases())` résultats.
     *
     * @return list<AboutSiteCard> triées par locale ASC
     */
    public function findByTranslationGroup(Uuid $translationGroup): array;

    public function save(AboutSiteCard $card): void;

    public function remove(AboutSiteCard $card): void;
}
