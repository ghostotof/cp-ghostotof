<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use DomainException;

/**
 * Exception métier levée lorsqu'un utilisateur ROLE_SUPER tente de supprimer
 * son propre compte depuis le backoffice.
 *
 * Message constant, qui ne cite aucun compte (issue #363) : le noyau et API
 * Platform le journalisent, et l'identifiant d'un invité est dérivé de son
 * adresse. L'appelant sait déjà de quel compte il s'agit ; le frontend lit le
 * statut (et le `type` quand il y en a un), jamais le `detail`.
 */
final class CannotDeleteOwnAccountException extends DomainException
{
    public static function forActingAccount(): self
    {
        return new self('Un compte ne peut pas supprimer son propre compte.');
    }
}
