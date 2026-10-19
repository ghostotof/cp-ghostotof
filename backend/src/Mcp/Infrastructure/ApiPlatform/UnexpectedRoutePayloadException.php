<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\ApiPlatform;

use LogicException;

/**
 * La normalisation d'une route n'a pas rendu un tableau JSON : défaut de
 * câblage (ressource sans structure), jamais une erreur du client.
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
