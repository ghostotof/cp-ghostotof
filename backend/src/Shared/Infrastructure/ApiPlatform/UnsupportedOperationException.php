<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;

/**
 * Un Processor a reçu une opération que sa ressource ne déclare pas (issue #338).
 *
 * Défaut de câblage d'un `#[ApiResource]`, jamais une requête cliente : le
 * routeur ne publie que les opérations déclarées. Elle sort donc en 500
 * `critical`, sans `ProblemExceptionInterface` ni entrée `exception_to_status`.
 * Levée par DispatchesWriteOperations, le `process()` commun des Processors
 * CRUD du backoffice ; la trace dit lequel l'a reçue.
 */
final class UnsupportedOperationException extends \LogicException
{
    public static function forOperation(Operation $operation): self
    {
        return new self(\sprintf('Opération non gérée : %s.', $operation::class));
    }
}
