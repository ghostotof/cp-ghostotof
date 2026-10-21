<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Le résultat JSON d'un outil : l'objet en `structuredContent`, et ce même
 * JSON dans le bloc texte — beaucoup de clients ne passent que ce bloc au
 * modèle, qui doit y voir les mêmes données. Pour un outil adossé à une route
 * (ADR 0006 D1), l'objet est le payload de la route ; `get_contact_info`, sans
 * route, rend le sien sous la même forme.
 *
 * Construit ici plutôt que laissé au formateur du SDK, pour que les deux
 * formes viennent d'un seul payload, quelle que soit la révision du
 * protocole négociée.
 */
final class JsonToolResult
{
    // JSON_PRESERVE_ZERO_FRACTION, comme l'encodeur JSON du Serializer qui
    // écrit la route : un flottant entier (des années `1.0`) s'y écrit `1.0`.
    // `structuredContent`, encodé par le SDK sans ce drapeau, porte `1` : le
    // même nombre JSON, que l'outil ne peut pas écrire autrement.
    private const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /**
     * Une route qui rend un objet : `structuredContent` est cet objet, à
     * l'identique. Une liste, vide comprise, s'encoderait en tableau JSON :
     * refusée. Le refus de `[]` est fidèle à la route : sans
     * `preserve_empty_objects`, le Serializer écrit `[]` un objet dont tous
     * les champs sont omis — un défaut de la route, que l'outil ne masque pas
     * (#681, accepté).
     *
     * @param array<mixed> $payload
     *
     * @throws ToolPayloadShapeException le payload est une liste
     */
    public static function forObject(array $payload): CallToolResult
    {
        if (array_is_list($payload)) {
            throw ToolPayloadShapeException::listGivenAsObject();
        }

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
     *
     * @throws ToolPayloadShapeException le payload n'est pas une liste
     */
    public static function forCollection(array $items): CallToolResult
    {
        if (!array_is_list($items)) {
            throw ToolPayloadShapeException::objectGivenAsCollection();
        }

        return self::forObject(['items' => $items]);
    }
}
