<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Levée lorsqu'un même client (identifié par IP, cf.
 * App\Security\User\Infrastructure\RateLimiter\SymfonyPasswordSetupRateLimiter)
 * dépasse le quota d'appels autorisé sur /api/account/password-setup/{token}.
 * Mappée sur HTTP 429 via exception_to_status (cf. api_platform.yaml) ;
 * l'en-tête Retry-After est posé par PasswordSetupRateLimitRetryAfterListener.
 */
final class PasswordSetupRateLimitExceededException extends \DomainException
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de tentatives depuis cette adresse IP. Réessayez plus tard.');
    }
}
