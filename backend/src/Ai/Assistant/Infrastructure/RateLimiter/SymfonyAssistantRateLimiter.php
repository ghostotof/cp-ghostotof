<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\RateLimiter;

use App\Ai\Assistant\Application\AssistantRateLimiterInterface;
use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse AssistantRateLimiterInterface au limiteur "career_assistant" de
 * config/packages/rate_limiter.yaml (fenêtre glissante, 30 appels/heure),
 * clé = identifiant du compte.
 */
final readonly class SymfonyAssistantRateLimiter implements AssistantRateLimiterInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.career_assistant')]
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function consume(string $accountIdentifier): void
    {
        $limit = $this->rateLimiterFactory->create($accountIdentifier)->consume();

        if (!$limit->isAccepted()) {
            throw new AssistantRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
