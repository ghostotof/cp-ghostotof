<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente de renvoyer une invitation à un compte
 * qui n'est pas en attente d'activation : soit son mot de passe a déjà été
 * défini, soit c'est un compte créé en CLI (jamais invité, sans e-mail).
 */
final class AccountNotAwaitingActivationException extends \DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(sprintf('Le compte "%s" n\'est pas en attente d\'activation : impossible de renvoyer une invitation.', $username));
    }
}
