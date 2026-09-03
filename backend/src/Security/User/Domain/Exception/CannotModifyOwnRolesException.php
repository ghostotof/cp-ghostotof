<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'un ROLE_SUPER tente de modifier ses propres
 * rôles depuis le backoffice (typiquement se rétrograder) : garde anti-lockout
 * accidentel, sur le même modèle que CannotDeleteOwnAccountException.
 */
final class CannotModifyOwnRolesException extends \DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(sprintf('L\'utilisateur "%s" ne peut pas modifier ses propres rôles.', $username));
    }
}
