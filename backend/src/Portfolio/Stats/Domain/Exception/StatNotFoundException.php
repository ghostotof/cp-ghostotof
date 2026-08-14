<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * statistique inconnue.
 */
final class StatNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucune statistique trouvée avec l\'id "%d".', $id));
    }
}
