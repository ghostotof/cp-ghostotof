<?php

declare(strict_types=1);

namespace App\Contact\Infrastructure\RateLimiter;

use App\Contact\Application\ContactRateLimiterInterface;
use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse ContactRateLimiterInterface au limiteur "contact_form" déclaré dans
 * config/packages/rate_limiter.yaml (fenêtre glissante, 5 requêtes/heure).
 */
final readonly class SymfonyContactRateLimiter implements ContactRateLimiterInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.contact_form')]
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function consume(string $clientIdentifier): void
    {
        $limit = $this->rateLimiterFactory->create($clientIdentifier)->consume();

        if (!$limit->isAccepted()) {
            throw new ContactRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
