<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer un
 * utilisateur absent (id inconnu).
 */
final class CpgUserNotFoundException extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Aucun utilisateur trouvé avec l\'identifiant %s.', $id->toRfc4122()));
    }
}
