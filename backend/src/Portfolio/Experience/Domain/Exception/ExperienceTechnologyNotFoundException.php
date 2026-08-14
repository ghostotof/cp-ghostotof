<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * technologie absente du classement (id inconnu).
 */
final class ExperienceTechnologyNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucune technologie trouvée avec l\'identifiant %d.', $id));
    }
}
