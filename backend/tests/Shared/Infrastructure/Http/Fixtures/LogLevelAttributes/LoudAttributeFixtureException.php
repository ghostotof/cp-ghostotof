<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * Fixture d'ExceptionLogLevelCoverageTest (issue #357) : un niveau hors
 * politique pour son statut, une 404 en `critical`.
 */
#[WithHttpStatus(404)]
#[WithLogLevel(LogLevel::CRITICAL)]
final class LoudAttributeFixtureException extends \DomainException
{
}
