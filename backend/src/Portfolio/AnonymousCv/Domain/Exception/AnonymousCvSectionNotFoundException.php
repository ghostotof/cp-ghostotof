<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * section inconnue du CV sans identité.
 */
final class AnonymousCvSectionNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucune section de CV sans identité trouvée avec l\'id "%s".', $id->toRfc4122()));
    }
}
