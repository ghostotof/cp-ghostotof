<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use LogicException;

/**
 * league demande d'enregistrer, pour un client, un code ou un jeton sur la
 * connexion MCP d'un autre client (#647) : deux consentements mêlés. league
 * vérifie que le code et le rafraîchissement reviennent au client à qui il
 * les a remis ; seul un défaut du serveur (une connexion mal choisie au
 * consentement) y mène. Aucune entrée `framework.exceptions`, un 500
 * `critical`. Le message ne cite aucun identifiant.
 */
final class McpConnectionOfAnotherClientException extends LogicException
{
    public static function forTokenClient(): self
    {
        return new self('Un code ou un jeton de connexion MCP a été émis pour un client sur la connexion d\'un autre client.');
    }
}
