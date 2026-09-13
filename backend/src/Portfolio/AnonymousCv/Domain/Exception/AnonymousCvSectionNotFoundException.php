<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * section inconnue du CV sans identité.
 */
final class AnonymousCvSectionNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucune section de CV sans identité trouvée avec l\'id "%d".', $id));
    }
}
