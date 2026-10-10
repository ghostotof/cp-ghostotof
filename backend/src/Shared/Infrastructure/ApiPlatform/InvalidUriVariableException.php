<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use InvalidArgumentException;

/**
 * Une variable d'URI attendue comme UUID est absente ou n'en est pas un
 * (issue #383).
 *
 * Défaut de câblage, jamais une requête cliente : chaque opération déclare
 * `requirements: ['id' => Requirement::UUID]`, donc un segment malformé est un
 * 404 du routeur avant tout Provider (ItemRouteRequirementTest). Arriver ici
 * veut dire qu'une opération a perdu ce `requirements` ou lit une autre clé :
 * une 500 `critical`, sans `ProblemExceptionInterface` ni entrée
 * `exception_to_status`. Le message nomme la clé, écrite dans le code, jamais
 * la valeur reçue.
 */
final class InvalidUriVariableException extends InvalidArgumentException
{
    public static function notAUuid(string $key): self
    {
        return new self(\sprintf('La variable d\'URI "%s" doit être un UUID valide.', $key));
    }
}
