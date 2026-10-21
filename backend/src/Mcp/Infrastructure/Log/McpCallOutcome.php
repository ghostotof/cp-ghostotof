<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

/**
 * L'issue d'une ligne du journal d'appels MCP (spec 0007, « Journal des
 * appels ») : la valeur sur laquelle filtrer (`jq 'select(.context.outcome ==
 * "protocol-error")'`).
 */
enum McpCallOutcome: string
{
    case Success = 'success';

    /** Un outil a répondu une erreur (`isError: true`), que le modèle peut corriger. */
    case ToolError = 'tool-error';

    /** Une erreur JSON-RPC, ou une requête que le serveur n'a pas pu traiter. */
    case ProtocolError = 'protocol-error';
}
