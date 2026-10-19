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
    // JSON_PRESERVE_ZERO_FRACTION, comme l'encodeur JSON du Serializer qui
    // écrit la route : un flottant entier (des années `1.0`) s'y écrit `1.0`.
    // `structuredContent`, encodé par le SDK sans ce drapeau, porte `1` : le
    // même nombre JSON, que l'outil ne peut pas écrire autrement.
    private const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

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

    /**
     * Une route qui rend une collection : la spécification MCP veut un objet
     * pour `structuredContent`, le tableau de la route y est donc enveloppé
     * dans `{"items": …}` — seule transformation admise (ADR 0006 D1). Une
     * collection vide donne `{"items": []}`, comme la route rend `[]`.
     *
     * @param array<mixed> $items le tableau rendu par la route, tel quel
     */
    public static function forCollection(array $items): CallToolResult
    {
        return self::forObject(['items' => $items]);
    }
}
