<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de créer un CpgUser avec un nom
 * d'utilisateur ne respectant pas CpgUser::USERNAME_PATTERN.
 */
final class InvalidUsernameException extends \DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(sprintf('Le nom d\'utilisateur "%s" est invalide : il doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").', $username));
    }
}
