<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer un
 * principe de qualité inconnu.
 */
final class QualityPrincipleNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucun principe de qualité trouvé avec l\'id "%d".', $id));
    }
}
