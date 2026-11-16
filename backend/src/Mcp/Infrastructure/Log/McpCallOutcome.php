<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

/**
 * L'issue d'une ligne du journal d'appels MCP (spec 0007, « Journal des
 * appels ») : la valeur sur laquelle filtrer, par exemple
 * `kubectl logs deploy/backend -c php-fpm | tools/logs-select.sh
 * '.context.outcome == "protocol-error"'` (le script écarte le bruit du
 * descripteur 2 et garde visible un enregistrement coupé, voir son en-tête).
 */
enum McpCallOutcome: string
{
    case Success = 'success';

    /** Un outil a répondu une erreur (`isError: true`), que le modèle peut corriger. */
    case ToolError = 'tool-error';

    /** Une erreur JSON-RPC, ou une requête que le serveur n'a pas pu traiter. */
    case ProtocolError = 'protocol-error';
}
