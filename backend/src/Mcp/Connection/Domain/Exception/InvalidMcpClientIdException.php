<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Un identifiant public proposé pour un client MCP n'est pas de ceux que
 * {@see \App\Mcp\Connection\Domain\ValueObject\McpClientId} admet.
 *
 * Levée à l'enregistrement d'un client (aujourd'hui la commande
 * `app:mcp:client:register`, qui la rattrape ; demain le backoffice, #652) :
 * aucune entrée `framework.exceptions` tant qu'aucune route ne la laisse
 * passer. Le message ne cite jamais la valeur reçue.
 */
final class InvalidMcpClientIdException extends InvalidArgumentException
{
    public static function malformed(): self
    {
        return new self('L\'identifiant du client doit compter 1 à 255 caractères parmi A-Z a-z 0-9 . _ : / -.');
    }
}
