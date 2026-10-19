<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use LogicException;

/**
 * Un outil MCP désigne une route qu'`access_control` (ou un attribut
 * `security` de l'opération) réserve à un palier supérieur au palier de base.
 * Sans connexion MCP, le serveur ne sert que les paliers anonyme et de base
 * (ADR 0006 D2) : c'est un défaut de câblage de l'outil, jamais une erreur du
 * client. Le SDK l'attrape, la journalise en `error` sur le canal `mcp` et
 * répond une erreur JSON-RPC `-32603` sans en dire plus.
 */
final class RouteAboveMcpTiersException extends LogicException
{
    /**
     * @param class-string $resourceClass
     */
    public static function forResource(string $resourceClass): self
    {
        return new self(\sprintf('La route de %s est réservée au-delà du palier de base : le serveur MCP ne peut pas la servir sans connexion MCP.', $resourceClass));
    }
}
