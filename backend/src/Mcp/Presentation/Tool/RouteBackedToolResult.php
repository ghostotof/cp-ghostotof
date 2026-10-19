<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Le résultat d'un outil adossé à une route (ADR 0006 D1) : le payload de la
 * route en `structuredContent`, et ce même JSON dans le bloc texte — beaucoup
 * de clients ne passent que ce bloc au modèle, qui doit y voir les mêmes
 * données.
 *
 * Construit ici plutôt que laissé au formateur du SDK, pour que les deux
 * formes viennent d'un seul payload, quelle que soit la révision du
 * protocole négociée.
 */
final class RouteBackedToolResult
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /**
     * Une route qui rend un objet : `structuredContent` est cet objet, à
     * l'identique.
     *
     * @param array<mixed> $payload
     */
    public static function forObject(array $payload): CallToolResult
    {
        return new CallToolResult(
            [new TextContent(json_encode($payload, self::JSON_FLAGS))],
            structuredContent: $payload,
        );
    }
}
