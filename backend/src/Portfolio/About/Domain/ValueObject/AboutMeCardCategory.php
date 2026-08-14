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
}
