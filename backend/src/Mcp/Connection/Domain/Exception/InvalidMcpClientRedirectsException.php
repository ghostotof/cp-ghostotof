<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Le nombre de redirections proposées pour un client MCP n'est pas de ceux que
 * {@see \App\Mcp\Connection\Domain\Entity\McpClient} admet : de une à dix
 * redirections distinctes.
 *
 * Levée à l'enregistrement d'un client (la commande
 * `app:mcp:client:register`, qui la rattrape ; demain le backoffice, #652) :
 * aucune entrée `framework.exceptions` tant qu'aucune route ne la laisse
 * passer. Le message ne cite aucune valeur reçue.
 */
final class InvalidMcpClientRedirectsException extends InvalidArgumentException
{
    public static function none(): self
    {
        return new self('Un client doit déclarer au moins une redirection.');
    }

    public static function tooMany(): self
    {
        return new self('Un client ne peut pas déclarer plus de 10 redirections distinctes.');
    }
}
