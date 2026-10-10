<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Un même compte a dépassé le quota horaire de l'assistant de traduction
 * (ADR 0004, D5 : première borne de coût, avec le plafond de jetons et le
 * timeout). Mappée 429 via exception_to_status, avec le `type`
 * `/errors/rate-limited` de tout refus de débit, celui du quota de l'assistant
 * de parcours compris (issue #369) : sans ProblemExceptionInterface, API
 * Platform en déduisait `/errors/429` du seul statut. L'en-tête Retry-After
 * est posé par {@see \App\Shared\Infrastructure\Http\RetryAfterListener} à
 * partir de $retryAfter (RetryAfterAware).
 */
final class TranslationRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use HasProblemType;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct("Quota horaire de l'assistant de traduction atteint pour ce compte. Réessayez plus tard.");
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
