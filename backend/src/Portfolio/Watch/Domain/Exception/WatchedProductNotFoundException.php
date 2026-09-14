<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger, modifier ou supprimer un
 * produit surveillé inconnu.
 */
final class WatchedProductNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucun produit surveillé trouvé avec l\'id "%s".', $id->toRfc4122()));
    }
}
