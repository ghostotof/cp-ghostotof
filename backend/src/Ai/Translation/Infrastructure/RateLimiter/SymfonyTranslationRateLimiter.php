<?php

declare(strict_types=1);

namespace App\Ai\Translation\Infrastructure\RateLimiter;

use App\Ai\Translation\Application\TranslationRateLimiterInterface;
use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse TranslationRateLimiterInterface au limiteur "translation_assistant"
 * de config/packages/rate_limiter.yaml (fenêtre glissante, 30 appels/heure),
 * clé = identifiant du compte.
 *
 * Un refus est tracé sur `ai_usage` (niveau fixe, voir monolog.yaml) avec le
 * compte et l'échéance, comme celui de l'assistant de parcours (issue #356) :
 * le 429 que le noyau journalise en `info` sur le canal applicatif ne sort
 * jamais d'un pod de production, et un compte ROLE_SUPER compromis qui
 * boucle sur son plafond passerait inaperçu. Ici plutôt que dans le
 * processeur : c'est le seul point qui connaît à la fois la clé et
 * l'échéance du refus.
 */
#[WithMonologChannel('ai_usage')]
final readonly class SymfonyTranslationRateLimiter implements TranslationRateLimiterInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.translation_assistant')]
        private RateLimiterFactory $rateLimiterFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function consume(string $accountIdentifier): void
    {
        $limit = $this->rateLimiterFactory->create($accountIdentifier)->consume();

        if (!$limit->isAccepted()) {
            $this->logger->info('Assistant de traduction : quota atteint.', [
                'outcome' => 'rate-limited',
                'account' => $accountIdentifier,
                'retryAfter' => $limit->getRetryAfter()->format(\DATE_ATOM),
            ]);

            throw new TranslationRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
