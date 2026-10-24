<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Registry;

use LogicException;

/**
 * Un outil du registre ne déclare pas ses `securitySchemes` dans son
 * `_meta` (`#[McpTool(meta: ['securitySchemes' => …])]`) : défaut de câblage,
 * jamais une erreur du client. `tools/list` n'est pas servi plutôt qu'un
 * outil publié sans schéma, ou complété d'un schéma inventé ({@see SecuritySchemesRegistry}).
 *
 * Une `LogicException`, jamais une {@see \InvalidArgumentException}, que le
 * SDK rend en `-32602` (« paramètres invalides », la faute du client). Une
 * `LogicException`, il l'attrape, la journalise en `error` sur le canal `mcp`
 * et répond une erreur JSON-RPC `-32603` sans en dire plus.
 */
final class ToolWithoutSecuritySchemesException extends LogicException
{
    public static function forTool(string $toolName): self
    {
        return new self(\sprintf(
            'L\'outil MCP « %s » ne déclare pas de securitySchemes valides dans son _meta (une liste non vide d\'objets portant un « type ») : il ne peut pas être publié.',
            $toolName,
        ));
    }
}
