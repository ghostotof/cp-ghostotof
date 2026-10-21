<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use LogicException;

/**
 * La normalisation d'une route n'a pas rendu un tableau JSON : défaut de
 * câblage (ressource sans structure), jamais une erreur du client. Le SDK
 * l'attrape, la journalise en `error` sur le canal `mcp` et répond `-32603`.
 *
 * Un provider qui rendrait `null` (la route répondrait 404) arriverait ici :
 * aucun outil adossé à une route d'item n'existe ; le premier traduira ce cas
 * en erreur d'outil avant de l'exposer.
 */
final class UnexpectedRoutePayloadException extends LogicException
{
    /**
     * @param class-string $resourceClass
     */
    public static function forResource(string $resourceClass, string $type): self
    {
        return new self(\sprintf('La route de %s se normalise en %s au lieu d\'un objet ou d\'une liste JSON.', $resourceClass, $type));
    }
}
