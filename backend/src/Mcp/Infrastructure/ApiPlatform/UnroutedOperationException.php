<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use LogicException;
use Throwable;

/**
 * Le routeur ne connaît aucune route sous le nom de l'opération qu'un outil
 * lit : sans le chemin réel, le palier ne peut pas être lu, et le lecteur
 * refuse plutôt que de deviner (défaut de câblage, jamais une erreur client).
 */
final class UnroutedOperationException extends LogicException
{
    /**
     * @param class-string $resourceClass
     */
    public static function forOperation(string $resourceClass, string $operationName, Throwable $previous): self
    {
        return new self(\sprintf('Aucune route ne porte le nom de l\'opération « %s » de %s : le serveur MCP refuse de la servir.', $operationName, $resourceClass), previous: $previous);
    }
}
