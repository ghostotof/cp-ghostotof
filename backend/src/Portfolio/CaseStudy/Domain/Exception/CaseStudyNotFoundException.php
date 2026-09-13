<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * étude de cas inconnue.
 */
final class CaseStudyNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucune étude de cas trouvée avec l\'id "%d".', $id));
    }
}
