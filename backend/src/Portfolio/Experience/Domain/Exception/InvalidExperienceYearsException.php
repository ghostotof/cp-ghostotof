<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Exception;

/**
 * Exception métier levée quand le temps cumulé d'une technologie sort de
 * l'intervalle publiable (issue #372) : négatif, au-delà de la borne haute, ou
 * non fini (INF, NaN) — ce dernier cas ne s'encode pas en JSON et mettait la
 * route publique GET /api/experience/technologies en 500.
 */
final class InvalidExperienceYearsException extends \DomainException
{
    public static function outOfRange(float $years, float $maxYears): self
    {
        return new self(sprintf(
            'Le temps cumulé doit être un nombre compris entre 0 et %s ans (reçu : %s).',
            $maxYears,
            // PHP 8.5 émet un warning en convertissant NAN en chaîne : on le nomme.
            is_nan($years) ? 'NaN' : $years,
        ));
    }
}
