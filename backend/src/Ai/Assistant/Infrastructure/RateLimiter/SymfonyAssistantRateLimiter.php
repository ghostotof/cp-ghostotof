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
 * clé = UUID du compte (issue #363 : l'identifiant de connexion d'un invité
 * est dérivé de son adresse, et le stockage du limiteur n'en garde qu'un
 * SHA-1 sans clé, facile à confirmer pour qui devine le nom).
 */
final readonly class SymfonyAssistantRateLimiter implements AssistantRateLimiterInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.career_assistant')]
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function consume(string $accountId): void
    {
        $limit = $this->rateLimiterFactory->create($accountId)->consume();

        if (!$limit->isAccepted()) {
            throw new AssistantRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
