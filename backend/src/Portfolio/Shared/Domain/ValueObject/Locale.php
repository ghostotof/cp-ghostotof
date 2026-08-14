<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\ValueObject;

/**
 * Langue du contenu portfolio géré en base (About/Quality/Stats/Experience).
 * Miroir du type `Locale` côté frontend (frontend/src/domain/portfolio/entities/Locale.ts).
 */
enum Locale: string
{
    case FR = 'fr';
    case EN = 'en';
}
