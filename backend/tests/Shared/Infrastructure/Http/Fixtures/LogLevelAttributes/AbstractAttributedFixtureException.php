<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Fixture d'ExceptionLogLevelCoverageTest (issue #357) : une exception
 * abstraite qui porte #[WithHttpStatus]. Jamais levée telle quelle, elle n'est
 * pas une exception rendue : seules ses sous-classes concrètes le sont.
 */
#[WithHttpStatus(404)]
abstract class AbstractAttributedFixtureException extends \DomainException
{
}
