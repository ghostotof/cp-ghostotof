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

    public function assertWithinQuota(string $clientIdentifier): void
    {
        // consume(0) lit la fenêtre sans y rien inscrire, et rend toujours
        // « accepté » : c'est le nombre de jetons restants qui décide. À zéro,
        // l'échéance rendue est celle où le quota se libère.
        $limit = $this->rateLimiterFactory->create($clientIdentifier)->consume(0);

        if (0 === $limit->getRemainingTokens()) {
            throw new ContactRateLimitExceededException($limit->getRetryAfter());
        }
    }

    public function record(string $clientIdentifier): void
    {
        // Le résultat est ignoré à dessein : refusé (soumission concurrente),
        // consume() n'inscrit rien, et le message est parti de toute façon.
        $this->rateLimiterFactory->create($clientIdentifier)->consume();
    }
}
