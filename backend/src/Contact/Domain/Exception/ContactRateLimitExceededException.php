<?php

declare(strict_types=1);

namespace App\Contact\Domain\Exception;

use App\Shared\Domain\Exception\RetryAfterAware;

/**
 * Exception métier levée lorsqu'un même client (identifié par IP, cf.
 * App\Contact\Infrastructure\RateLimiter\SymfonyContactRateLimiter) dépasse le
 * quota de soumissions autorisé sur le formulaire de contact. Mappée sur HTTP
 * 429 via exception_to_status (cf. config/packages/api_platform.yaml).
 */
final class ContactRateLimitExceededException extends \DomainException implements RetryAfterAware
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de messages envoyés depuis cette adresse IP. Réessayez plus tard.');
    }
}
