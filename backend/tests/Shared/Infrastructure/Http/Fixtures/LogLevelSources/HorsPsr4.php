<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;

/*
 * Fixture d'ExceptionLogLevelCoverageTest (issue #348) : un fichier qui
 * déclare deux exceptions, dont aucune ne porte son nom. Une découverte qui
 * déduirait la classe du chemin (PSR-4) ne verrait ni l'une ni l'autre.
 */

final class UnloggedFixtureProblemException extends \DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    protected function problemType(): string
    {
        return 'unlogged-fixture';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}

final class LoggedFixtureProblemException extends \DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    protected function problemType(): string
    {
        return 'logged-fixture';
    }

    protected function problemStatus(): int
    {
        return 422;
    }
}
