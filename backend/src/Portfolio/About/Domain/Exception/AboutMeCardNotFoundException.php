<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * carte "À propos de moi" inconnue.
 */
final class AboutMeCardNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucune carte "À propos de moi" trouvée avec l\'id "%d".', $id));
    }
}
