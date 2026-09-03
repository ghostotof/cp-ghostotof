<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente d'inviter un utilisateur avec une
 * adresse e-mail déjà rattachée à un compte existant.
 */
final class EmailAlreadyUsedException extends \DomainException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('Un utilisateur existe déjà avec l\'adresse e-mail "%s".', $email));
    }
}
