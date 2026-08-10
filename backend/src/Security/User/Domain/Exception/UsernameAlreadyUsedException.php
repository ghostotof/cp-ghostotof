<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de créer un CpgUser avec un nom
 * d'utilisateur déjà utilisé par un autre compte.
 */
final class UsernameAlreadyUsedException extends \DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(sprintf('Un utilisateur existe déjà avec le nom d\'utilisateur "%s".', $username));
    }
}
