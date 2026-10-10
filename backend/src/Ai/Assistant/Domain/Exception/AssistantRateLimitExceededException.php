<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\IsRateLimitedProblem;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Un même compte a dépassé le quota horaire de l'assistant de parcours
 * (spec 0005 D6). 429 avec le `type` stable de tout refus de débit
 * (`/errors/rate-limited`, {@see IsRateLimitedProblem}, le même que celui que
 * rend la zone nginx `assistant`) : le frontend n'a qu'une raison à
 * reconnaître, quelle que soit la borne atteinte. Rendue par
 * ApiProblemResponseListener (Shared) ; l'en-tête Retry-After est posé par
 * {@see \App\Shared\Infrastructure\Http\RetryAfterListener} à partir de $retryAfter
 * (RetryAfterAware).
 */
final class AssistantRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use IsRateLimitedProblem;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct("Quota horaire de l'assistant atteint pour ce compte. Réessayez plus tard.");
    }
}
