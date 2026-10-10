<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\IsRateLimitedProblem;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Levée lorsqu'un même client (identifié par IP, cf.
 * {@see \App\Security\User\Infrastructure\RateLimiter\SymfonyPasswordSetupRateLimiter})
 * dépasse le quota d'appels autorisé sur /api/account/password-setup.
 * 429 avec le `type` `/errors/rate-limited` de tout refus de débit, zones nginx
 * comprises ({@see IsRateLimitedProblem}, issue #369) : API Platform prend le
 * statut à getStatus(), sans entrée `exception_to_status`. Sans
 * ProblemExceptionInterface, il en déduisait `/errors/429`. L'en-tête
 * Retry-After est posé par
 * {@see \App\Shared\Infrastructure\Http\RetryAfterListener} à partir de
 * $retryAfter (RetryAfterAware).
 */
final class PasswordSetupRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use IsRateLimitedProblem;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de tentatives depuis cette adresse IP. Réessayez plus tard.');
    }
}
