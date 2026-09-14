<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * contribution inconnue.
 */
final class ContributionNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucune contribution trouvée avec l\'id "%s".', $id->toRfc4122()));
    }
}
