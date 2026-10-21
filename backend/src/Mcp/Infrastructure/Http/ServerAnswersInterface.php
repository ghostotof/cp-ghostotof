<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * La provenance des réponses de `/api/mcp` (audit de #675, C9) : ce que le
 * serveur MCP a répondu lui-même, par opposition à un 4xx ou un 5xx du
 * framework sous la même URL. Implémentée par {@see ServerAnswers}.
 *
 * Ici plutôt que dans `Mcp/Application` : le contrat porte sur une réponse
 * HTTP, et aucune interface de la couche Application ne dépend de
 * HttpFoundation.
 */
interface ServerAnswersInterface
{
    /**
     * Inscrit la réponse que le serveur rend, et la rend.
     */
    public function record(Response $response): Response;

    /**
     * Vrai pour l'objet même qu'a inscrit {@see self::record()} — pas pour une
     * réponse égale qui l'aurait remplacé.
     */
    public function recorded(Response $response): bool;
}
