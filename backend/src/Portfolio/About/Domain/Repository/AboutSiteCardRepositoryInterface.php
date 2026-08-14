<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Repository;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (AboutSiteCardAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans Infrastructure\Doctrine\AboutSiteCardRepository.
 */
interface AboutSiteCardRepositoryInterface
{
    public function findOneById(int $id): ?AboutSiteCard;

    /**
     * @return list<AboutSiteCard> triées par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<AboutSiteCard> triées par locale puis position ASC
     */
    public function findAll(): array;

    public function save(AboutSiteCard $card): void;

    public function remove(AboutSiteCard $card): void;
}
