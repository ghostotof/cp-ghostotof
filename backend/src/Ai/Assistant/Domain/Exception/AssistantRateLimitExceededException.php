<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use App\Shared\Domain\Exception\RetryAfterAware;

/**
 * Un même compte a dépassé le quota horaire de l'assistant de parcours
 * (spec 0005 D6). 429 avec un `type` stable (`/errors/rate-limited`, le même
 * que celui que rend la zone nginx `assistant`) : le frontend n'a qu'une
 * raison à reconnaître, quelle que soit la borne atteinte. Rendue par
 * ApiProblemResponseListener (Shared) ; l'en-tête Retry-After est posé par
 * App\Shared\Infrastructure\Http\RetryAfterListener à partir de $retryAfter
 * (RetryAfterAware).
 */
final class AssistantRateLimitExceededException extends \DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use HasProblemType;

    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct("Quota horaire de l'assistant atteint pour ce compte. Réessayez plus tard.");
    }

    protected function problemType(): string
    {
        return 'rate-limited';
    }

    protected function problemStatus(): int
    {
        return 429;
    }
}
