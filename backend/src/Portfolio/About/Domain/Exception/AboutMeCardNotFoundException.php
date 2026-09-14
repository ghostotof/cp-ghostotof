<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * carte "À propos de moi" inconnue.
 */
final class AboutMeCardNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucune carte "À propos de moi" trouvée avec l\'id "%s".', $id->toRfc4122()));
    }
}
