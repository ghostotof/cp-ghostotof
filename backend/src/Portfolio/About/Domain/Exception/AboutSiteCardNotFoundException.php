<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * carte "À propos de ce site" inconnue.
 */
final class AboutSiteCardNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucune carte "À propos de ce site" trouvée avec l\'id "%d".', $id));
    }
}
