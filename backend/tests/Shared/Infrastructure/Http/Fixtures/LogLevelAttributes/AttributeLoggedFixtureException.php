<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * Fixture d'ExceptionLogLevelCoverageTest (issue #357) : une exception qui fixe
 * son niveau par #[WithLogLevel], que le noyau lit à défaut d'entrée dans
 * `framework.exceptions` (ErrorListener::resolveLogLevel). Un fichier par
 * classe, autoloadable : un test qui ne nomme que son `::class` ne dépend pas
 * d'un autre qui l'aurait chargé avant lui.
 */
#[WithHttpStatus(404)]
#[WithLogLevel(LogLevel::INFO)]
class AttributeLoggedFixtureException extends \DomainException
{
}
