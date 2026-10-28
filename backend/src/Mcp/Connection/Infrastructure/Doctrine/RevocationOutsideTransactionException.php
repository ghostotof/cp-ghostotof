<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use LogicException;

/**
 * La révocation de toutes les connexions d'un compte a été demandée hors
 * transaction (#650) : un défaut de l'appelant, jamais une issue ordinaire.
 *
 * Hors transaction, l'instruction seule serait validée tout de suite, avant
 * le reste de l'opération de l'appelant (le nouveau mot de passe, le rôle
 * retiré) : un échec de celle-ci laisserait des connexions coupées pour un
 * compte inchangé, et inversement. D'où un refus avant d'écrire quoi que ce
 * soit, sans mapping HTTP : un 500 `critical` qui porte un nom. Le message ne
 * cite que l'UUID du compte, jamais son identifiant (#363).
 */
final class RevocationOutsideTransactionException extends LogicException
{
    public static function forAccount(string $accountId): self
    {
        return new self(\sprintf('La révocation de toutes les connexions MCP du compte %s se fait dans la transaction de l\'appelant : hors transaction, elle serait validée avant le reste de son opération.', $accountId));
    }
}
