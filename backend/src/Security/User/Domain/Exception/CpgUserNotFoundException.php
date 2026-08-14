<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier/supprimer un
 * utilisateur absent (id inconnu).
 */
final class CpgUserNotFoundException extends \DomainException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Aucun utilisateur trouvé avec l\'identifiant %d.', $id));
    }
}
