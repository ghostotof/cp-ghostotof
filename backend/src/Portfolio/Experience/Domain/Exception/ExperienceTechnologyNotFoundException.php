<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * technologie absente du classement (id inconnu).
 */
final class ExperienceTechnologyNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucune technologie trouvée avec l\'identifiant %s.', $id->toRfc4122()));
    }
}
