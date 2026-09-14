<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer un
 * trait de qualité inconnu.
 */
final class QualityTraitNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucun trait de qualité trouvé avec l\'id "%s".', $id->toRfc4122()));
    }
}
