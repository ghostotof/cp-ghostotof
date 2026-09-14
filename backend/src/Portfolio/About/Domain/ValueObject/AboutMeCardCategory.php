<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\ValueObject;

/**
 * Colonne de rangement d'une AboutMeCard dans la section "À propos de moi"
 * (frontend/src/domain/portfolio/entities/AboutContent.ts : technicalCards,
 * personalCards, hobbiesCards).
 */
enum AboutMeCardCategory: string
{
    case TECHNICAL = 'technical';
    case PERSONAL = 'personal';
    case HOBBY = 'hobby';

    /**
     * Les catégories gérées, dans l'ordre de déclaration.
     *
     * Même rôle que `Locale::values()` : le champ `category` de
     * BackofficeAboutMeCardOrderResource se borne avec
     * `#[Assert\Choice(callback: [AboutMeCardCategory::class, 'values'])]`
     * plutôt qu'avec une liste littérale — une quatrième colonne devient alors
     * un `case` de plus ici, et rien d'autre.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
