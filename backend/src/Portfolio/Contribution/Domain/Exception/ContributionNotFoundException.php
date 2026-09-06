<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer une
 * contribution inconnue.
 */
final class ContributionNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucune contribution trouvée avec l\'id "%d".', $id));
    }
}
