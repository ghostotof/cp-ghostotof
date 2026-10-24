<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use LogicException;

/**
 * league demande d'enregistrer un code ou un jeton pour un « utilisateur »
 * qui n'est pas une connexion MCP existante (#647). Le point d'autorisation
 * crée la connexion avant de compléter la demande, et league relit
 * l'identifiant qu'il a lui-même chiffré : seul un défaut du serveur y mène.
 * Jamais une erreur OAuth renvoyée au client : aucune entrée
 * `framework.exceptions`, un 500 `critical`.
 *
 * Le message ne cite pas l'identifiant.
 */
final class UnknownMcpConnectionException extends LogicException
{
    public static function notAConnectionIdentifier(): self
    {
        return new self('Un code ou un jeton de connexion MCP doit être émis pour une connexion, désignée par son UUID.');
    }

    public static function unknown(): self
    {
        return new self('Un code ou un jeton de connexion MCP a été émis pour une connexion qui n\'existe pas.');
    }
}
