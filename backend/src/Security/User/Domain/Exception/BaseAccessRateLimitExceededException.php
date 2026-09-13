<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Levée lorsqu'un même client (identifié par IP, cf.
 * App\Security\User\Infrastructure\RateLimiter\SymfonyBaseAccessRateLimiter)
 * dépasse le quota d'appels autorisé sur l'endpoint d'accès au palier de base
 * (ADR 0003 D6). Mappée sur HTTP 429 via exception_to_status.
 */
final class BaseAccessRateLimitExceededException extends \DomainException
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de tentatives depuis cette adresse IP. Réessayez plus tard.');
    }
}
