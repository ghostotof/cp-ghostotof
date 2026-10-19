<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use LogicException;

/**
 * Un outil MCP désigne une ressource qui n'a pas exactement une opération GET
 * publique : défaut de câblage, jamais une erreur du client (500 `critical`).
 */
final class RouteOperationNotFoundException extends LogicException
{
    /**
     * @param class-string $resourceClass
     */
    public static function forResource(string $resourceClass, int $found): self
    {
        return new self(\sprintf(
            'La ressource %s doit avoir exactement une opération GET publique pour être exposée au serveur MCP ; %d trouvée(s).',
            $resourceClass,
            $found,
        ));
    }
}
