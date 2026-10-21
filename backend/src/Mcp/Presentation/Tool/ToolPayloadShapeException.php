<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use LogicException;

/**
 * Un outil a passé à {@see JsonToolResult} un payload dont la forme
 * contredit la méthode choisie : un objet pour une collection, une liste pour
 * un objet. Défaut de câblage (la route n'a pas la forme que l'outil
 * suppose), jamais une erreur client — le SDK le rend en `-32603`, journalisé.
 */
final class ToolPayloadShapeException extends LogicException
{
    public static function objectGivenAsCollection(): self
    {
        return new self('Un outil a passé un objet comme collection : la route ne rend pas une liste.');
    }

    public static function listGivenAsObject(): self
    {
        return new self('Un outil a passé une liste (ou un payload vide) comme objet : structuredContent doit être un objet JSON.');
    }
}
