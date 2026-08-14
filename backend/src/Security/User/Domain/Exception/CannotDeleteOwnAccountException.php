<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'un utilisateur ROLE_SUPER tente de supprimer
 * son propre compte depuis le backoffice.
 */
final class CannotDeleteOwnAccountException extends \DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(sprintf('L\'utilisateur "%s" ne peut pas supprimer son propre compte.', $username));
    }
}
