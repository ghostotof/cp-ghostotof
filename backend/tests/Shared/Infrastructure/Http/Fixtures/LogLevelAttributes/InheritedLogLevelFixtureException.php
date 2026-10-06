<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes;

/**
 * Fixture d'ExceptionLogLevelCoverageTest (issue #357) : hérite des attributs
 * de sa parente sans les redéclarer, comme le noyau les résout.
 */
final class InheritedLogLevelFixtureException extends AttributeLoggedFixtureException
{
}
