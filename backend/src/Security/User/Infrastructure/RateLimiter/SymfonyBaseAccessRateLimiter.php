<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\RateLimiter;

use App\Security\User\Application\BaseAccessRateLimiterInterface;
use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse BaseAccessRateLimiterInterface au limiteur "base_access" déclaré
 * dans config/packages/rate_limiter.yaml (fenêtre glissante, 20/heure). Même
 * patron que SymfonyPasswordSetupRateLimiter/SymfonyContactRateLimiter.
 */
final readonly class SymfonyBaseAccessRateLimiter implements BaseAccessRateLimiterInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.base_access')]
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function consume(string $clientIdentifier): void
    {
        $limit = $this->rateLimiterFactory->create($clientIdentifier)->consume();

        if (!$limit->isAccepted()) {
            throw new BaseAccessRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
