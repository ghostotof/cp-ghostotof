<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use LogicException;

/**
 * Un outil MCP désigne une ressource qui n'a pas exactement une opération GET
 * servie sans connexion MCP : défaut de câblage, jamais une erreur du client.
 * Le SDK l'attrape, la journalise en `error` sur le canal `mcp` et répond une
 * erreur JSON-RPC `-32603` sans en dire plus.
 */
final class RouteOperationNotFoundException extends LogicException
{
    /**
     * @param class-string $resourceClass
     */
    public static function forResource(string $resourceClass, int $found): self
    {
        return new self(\sprintf(
            'La ressource %s doit avoir exactement une opération GET servie sans connexion MCP pour être exposée au serveur MCP ; %d trouvée(s).',
            $resourceClass,
            $found,
        ));
    }
}
