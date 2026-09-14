<?php

declare(strict_types=1);

namespace App\Ai\Translation\Infrastructure\RateLimiter;

use App\Ai\Translation\Application\TranslationRateLimiterInterface;
use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse TranslationRateLimiterInterface au limiteur "translation_assistant"
 * de config/packages/rate_limiter.yaml (fenêtre glissante, 30 appels/heure),
 * clé = identifiant du compte.
 */
final readonly class SymfonyTranslationRateLimiter implements TranslationRateLimiterInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.translation_assistant')]
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function consume(string $accountIdentifier): void
    {
        $limit = $this->rateLimiterFactory->create($accountIdentifier)->consume();

        if (!$limit->isAccepted()) {
            throw new TranslationRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
