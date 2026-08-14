<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Repository;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (AboutMeCardAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans Infrastructure\Doctrine\AboutMeCardRepository.
 */
interface AboutMeCardRepositoryInterface
{
    public function findOneById(int $id): ?AboutMeCard;

    /**
     * @return list<AboutMeCard> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<AboutMeCard> triées par position ASC ; utilisé par AboutContentProvider
     *                           pour ranger chaque carte dans le bon tableau (technical/personal/hobby)
     *                           sans filtrer côté PHP après un findByLocale complet
     */
    public function findByLocaleAndCategory(Locale $locale, AboutMeCardCategory $category): array;

    /**
     * @return list<AboutMeCard> triées par locale puis catégorie puis position ASC
     */
    public function findAll(): array;

    public function save(AboutMeCard $card): void;

    public function remove(AboutMeCard $card): void;
}
