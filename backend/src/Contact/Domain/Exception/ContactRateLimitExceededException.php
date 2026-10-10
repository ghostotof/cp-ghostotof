<?php

declare(strict_types=1);

namespace App\Contact\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Exception métier levée lorsqu'un même client (identifié par IP, cf.
 * {@see \App\Contact\Infrastructure\RateLimiter\SymfonyContactRateLimiter}) dépasse le
 * quota de soumissions autorisé sur le formulaire de contact. Mappée sur HTTP
 * 429 via exception_to_status (cf. config/packages/api_platform.yaml), avec le
 * `type` `/errors/rate-limited` de tout refus de débit, zones nginx comprises
 * (issue #369) : sans ProblemExceptionInterface, API Platform en déduisait
 * `/errors/429` du seul statut. L'en-tête Retry-After est posé par
 * {@see \App\Shared\Infrastructure\Http\RetryAfterListener} (RetryAfterAware).
 */
final class ContactRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use HasProblemType;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de messages envoyés depuis cette adresse IP. Réessayez plus tard.');
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
