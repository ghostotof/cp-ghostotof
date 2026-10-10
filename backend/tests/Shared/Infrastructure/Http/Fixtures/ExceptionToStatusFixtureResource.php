<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use OverflowException;
use UnderflowException;

/**
 * Fixture d'ExceptionLogLevelCoverageTest (issue #357) : une ressource qui
 * mappe une exception au niveau de la ressource et une autre au niveau de
 * l'opération, ce que le vendor fusionne avec `api_platform.exception_to_status`
 * (ErrorListener::getOperationExceptionToStatus).
 *
 * Hors des chemins de mapping d'API Platform (src/) : elle ne publie aucune
 * route, le test la lit par la fabrique de métadonnées. Les exceptions sont des
 * classes natives qu'aucune entrée de `framework.exceptions` ne vise.
 */
#[ApiResource(
    operations: [new Get(exceptionToStatus: [OverflowException::class => 422])],
    exceptionToStatus: [UnderflowException::class => 409],
)]
final class ExceptionToStatusFixtureResource
{
    public ?string $id = null;
}
