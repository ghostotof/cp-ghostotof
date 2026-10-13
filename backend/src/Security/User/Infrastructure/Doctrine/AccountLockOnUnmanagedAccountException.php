<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use LogicException;

/**
 * Le verrou d'un compte a reçu une instance que l'EntityManager ne gère pas
 * (issue #560) : un défaut de l'appelant, que `AccountLockInterface` interdit.
 *
 * Le verrou relit le compte par une requête qui rafraîchit l'instance gérée
 * sur place ; une instance non gérée (détachée, venue d'un autre
 * EntityManager) ne serait pas celle que l'opération recevrait, et l'appelant
 * continuerait avec la sienne, périmée, sans le savoir. Avant #560,
 * `refresh()` levait déjà ; ce refus, avant de rien verrouiller, garde ce
 * comportement sous un nom. Sans mapping HTTP : un 500 `critical`. Le message
 * ne cite que l'UUID du compte, jamais son identifiant (issue #363).
 */
final class AccountLockOnUnmanagedAccountException extends LogicException
{
    public static function forAccount(string $userId): self
    {
        return new self(\sprintf('Le verrou du compte %s a reçu une instance que l\'EntityManager ne gère pas : la relecture en rendrait une autre, et l\'appelant garderait la sienne, périmée.', $userId));
    }
}
