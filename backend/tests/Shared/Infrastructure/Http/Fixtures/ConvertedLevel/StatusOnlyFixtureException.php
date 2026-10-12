<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\ConvertedLevel;

use DomainException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Fixture de CompiledExceptionConfigTest (PR #441) : un statut 4xx déclaré par
 * #[WithHttpStatus], sans niveau. Le noyau la convertit, et depuis Symfony
 * 8.1.8 lit son niveau par défaut sur la conversion : `error`.
 *
 * Seule dans son dossier : ExceptionLogLevelCoverageTest parcourt ceux de ses
 * propres fixtures et en attend des listes exactes.
 */
#[WithHttpStatus(404)]
final class StatusOnlyFixtureException extends DomainException
{
}
