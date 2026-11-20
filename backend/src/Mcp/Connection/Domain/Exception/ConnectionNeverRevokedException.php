<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use LogicException;

/**
 * Une connexion MCP sans `revoked_at` a été proposée là où l'ordre des
 * terminées l'exige (#652).
 *
 * Plus levée par personne depuis S4d : le curseur de la lecture par pages des
 * expirées échangées ({@see \App\Mcp\Connection\Domain\ValueObject\ExchangedExpiredCursor})
 * se clé sur l'activité, jamais nulle, et non plus sur `revoked_at`. Conservée
 * en attendant la décision de la retirer.
 *
 * Défaut de programmation, jamais une erreur client. Aucune entrée
 * `framework.exceptions`, un 500 `critical`. Le message ne cite aucun identifiant.
 */
final class ConnectionNeverRevokedException extends LogicException
{
    public static function noRevocationInstant(): self
    {
        return new self('Une connexion jamais révoquée n\'a pas de place dans la lecture par pages des expirées échangées.');
    }
}
