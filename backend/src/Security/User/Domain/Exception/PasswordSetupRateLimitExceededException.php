<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Levée lorsqu'un même client (identifié par IP, cf.
 * App\Security\User\Infrastructure\RateLimiter\SymfonyPasswordSetupRateLimiter)
 * dépasse le quota d'appels autorisé sur /api/account/password-setup.
 * Mappée sur HTTP 429 via exception_to_status (cf. api_platform.yaml) ;
 * l'en-tête Retry-After est posé par App\Shared\Infrastructure\Http\RetryAfterListener
 * à partir de $retryAfter (RetryAfterAware).
 */
final class PasswordSetupRateLimitExceededException extends DomainException implements RetryAfterAware
{
    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de tentatives depuis cette adresse IP. Réessayez plus tard.');
    }
}
