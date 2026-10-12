<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\IsRateLimitedProblem;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Un même compte a dépassé le quota horaire de l'assistant de traduction
 * (ADR 0004, D5 : première borne de coût, avec le plafond de jetons et le
 * timeout). 429 avec le `type` `/errors/rate-limited` de tout refus de
 * débit, zones nginx comprises ({@see IsRateLimitedProblem}, issue #369) :
 * API Platform prend le statut à getStatus(), sans entrée
 * `exception_to_status`. Sans ProblemExceptionInterface, il en déduisait
 * `/errors/429`. L'en-tête Retry-After est posé par
 * {@see \App\Shared\Infrastructure\Http\RetryAfterListener} à partir de
 * $retryAfter (RetryAfterAware).
 */
final class TranslationRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use IsRateLimitedProblem;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct("Quota horaire de l'assistant de traduction atteint pour ce compte. Réessayez plus tard.");
    }
}
