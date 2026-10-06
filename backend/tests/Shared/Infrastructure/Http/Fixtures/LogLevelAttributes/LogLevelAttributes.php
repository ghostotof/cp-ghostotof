<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/*
 * Fixture d'ExceptionLogLevelCoverageTest (issue #357) : des exceptions qui
 * fixent leur niveau par #[WithLogLevel], que le noyau lit à défaut d'entrée
 * dans `framework.exceptions` (ErrorListener::resolveLogLevel). L'une le
 * porte, une autre en hérite, la dernière fixe un niveau hors politique pour
 * son statut.
 */

#[WithHttpStatus(404)]
#[WithLogLevel(LogLevel::INFO)]
class AttributeLoggedFixtureException extends \DomainException
{
}

final class InheritedLogLevelFixtureException extends AttributeLoggedFixtureException
{
}

#[WithHttpStatus(404)]
#[WithLogLevel(LogLevel::CRITICAL)]
final class LoudAttributeFixtureException extends \DomainException
{
}
