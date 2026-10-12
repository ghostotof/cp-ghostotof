<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use DomainException;

/**
 * Exception métier levée lorsqu'on tente de renvoyer une invitation à un compte
 * qui n'est pas en attente d'activation : soit son mot de passe a déjà été
 * défini, soit c'est un compte créé en CLI (jamais invité, sans e-mail).
 *
 * Message constant, qui ne cite aucun compte (issue #363) : le noyau et API
 * Platform le journalisent, et l'identifiant d'un invité est dérivé de son
 * adresse. L'appelant sait déjà de quel compte il s'agit ; le frontend lit le
 * statut (et le `type` quand il y en a un), jamais le `detail`.
 */
final class AccountNotAwaitingActivationException extends DomainException
{
    public static function alreadyActivated(): self
    {
        return new self('Ce compte n\'est pas en attente d\'activation : impossible de renvoyer une invitation.');
    }
}
