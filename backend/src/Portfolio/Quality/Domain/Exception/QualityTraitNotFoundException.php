<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer un
 * trait de qualité inconnu.
 */
final class QualityTraitNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucun trait de qualité trouvé avec l\'id "%d".', $id));
    }
}
