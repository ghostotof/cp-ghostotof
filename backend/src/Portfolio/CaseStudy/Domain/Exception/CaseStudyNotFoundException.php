<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * étude de cas inconnue.
 */
final class CaseStudyNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucune étude de cas trouvée avec l\'id "%s".', $id->toRfc4122()));
    }
}
