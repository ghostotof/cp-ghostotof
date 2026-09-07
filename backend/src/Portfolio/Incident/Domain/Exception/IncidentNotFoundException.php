<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer un
 * incident inconnu.
 */
final class IncidentNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucun incident trouvé avec l\'id "%d".', $id));
    }
}
