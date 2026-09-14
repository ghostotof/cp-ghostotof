<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\Exception;

/**
 * Un même compte a dépassé le quota horaire de l'assistant de traduction
 * (ADR 0004, D5 : première borne de coût, avec le plafond de jetons et le
 * timeout). Mappée 429 via exception_to_status ; l'en-tête Retry-After est
 * posé par TranslationRateLimitRetryAfterListener.
 */
final class TranslationRateLimitExceededException extends \DomainException
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct("Quota horaire de l'assistant de traduction atteint pour ce compte. Réessayez plus tard.");
    }
}
