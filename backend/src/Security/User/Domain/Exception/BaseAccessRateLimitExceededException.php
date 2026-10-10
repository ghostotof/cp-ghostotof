<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Levée lorsqu'un même client (identifié par IP, cf.
 * App\Security\User\Infrastructure\RateLimiter\SymfonyBaseAccessRateLimiter)
 * dépasse le quota d'appels autorisé sur l'endpoint d'accès au palier de base
 * (ADR 0003 D6).
 *
 * POST /api/account/base-access est un contrôleur, pas une opération API
 * Platform : `exception_to_status` ne s'y applique pas. Le 429 est rendu par
 * App\Shared\Infrastructure\Http\ApiProblemResponseListener (issue #322), avec
 * le `type` `/errors/rate-limited` que portent déjà le quota de l'assistant et
 * la zone nginx `assistant` : un client n'a qu'une raison à reconnaître.
 * L'en-tête Retry-After est posé par RetryAfterListener (RetryAfterAware), et
 * le niveau de journalisation (`info`) est fixé dans `framework.exceptions`.
 */
final class BaseAccessRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use HasProblemType;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de tentatives depuis cette adresse IP. Réessayez plus tard.');
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
