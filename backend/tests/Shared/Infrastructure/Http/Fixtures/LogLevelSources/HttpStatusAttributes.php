<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources;

use DomainException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/*
 * Fixture d'ExceptionLogLevelCoverageTest (issue #357) : une exception qui
 * déclare son statut par #[WithHttpStatus], et une autre qui en hérite sans le
 * redéclarer. Le noyau convertit l'une et l'autre en HttpException, mais
 * résout leur niveau sur l'exception d'origine : sans entrée, `critical`.
 */

#[WithHttpStatus(404)]
class AttributedFixtureException extends DomainException
{
}

final class InheritingFixtureException extends AttributedFixtureException
{
}
