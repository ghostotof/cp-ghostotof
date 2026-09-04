<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\RateLimiter;

use App\Security\User\Application\PasswordSetupRateLimiterInterface;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse PasswordSetupRateLimiterInterface au limiteur "account_password_setup"
 * déclaré dans config/packages/rate_limiter.yaml (fenêtre glissante, 10/heure).
 * Même patron que SymfonyContactRateLimiter.
 */
final readonly class SymfonyPasswordSetupRateLimiter implements PasswordSetupRateLimiterInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.account_password_setup')]
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function consume(string $clientIdentifier): void
    {
        $limit = $this->rateLimiterFactory->create($clientIdentifier)->consume();

        if (!$limit->isAccepted()) {
            throw new PasswordSetupRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
