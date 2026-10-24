<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Un nom proposé pour un client MCP n'est pas de ceux que
 * {@see \App\Mcp\Connection\Domain\ValueObject\McpClientName} admet.
 *
 * Levée à l'enregistrement d'un client (la commande
 * `app:mcp:client:register`, qui la rattrape ; demain le backoffice, #652) :
 * aucune entrée `framework.exceptions` tant qu'aucune route ne la laisse
 * passer. Le message ne cite jamais la valeur reçue.
 */
final class InvalidMcpClientNameException extends InvalidArgumentException
{
    public static function malformed(): self
    {
        return new self('Le nom du client doit compter 1 à 100 caractères en UTF-8, sans caractère de contrôle ni saut de ligne.');
    }
}
