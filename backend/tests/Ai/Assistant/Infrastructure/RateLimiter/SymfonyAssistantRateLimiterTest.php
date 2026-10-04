<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\RateLimiter;

use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Assistant\Infrastructure\RateLimiter\SymfonyAssistantRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Calqué sur SymfonyTranslationRateLimiterTest (spec 0005 §8) : la clé est
 * le compte, jamais l'adresse IP.
 */
final class SymfonyAssistantRateLimiterTest extends TestCase
{
    private function createRateLimiter(int $limit): SymfonyAssistantRateLimiter
    {
        $factory = new RateLimiterFactory(
            ['id' => 'career_assistant_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new SymfonyAssistantRateLimiter($factory);
    }

    public function testItAcceptsCallsUnderTheLimit(): void
    {
        $rateLimiter = $this->createRateLimiter(2);

        $rateLimiter->consume('trusted');
        $rateLimiter->consume('trusted');

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsTheCallBeyondTheLimitWithARetryAfterDate(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->consume('trusted');

        try {
            $rateLimiter->consume('trusted');
            self::fail('Une exception était attendue.');
        } catch (AssistantRateLimitExceededException $exception) {
            self::assertGreaterThan(new \DateTimeImmutable(), $exception->retryAfter);
            self::assertSame('/errors/rate-limited', $exception->getType());
            self::assertSame(429, $exception->getStatus());
        }
    }

    public function testItTracksEachAccountIndependently(): void
    {
        $rateLimiter = $this->createRateLimiter(1);

        $rateLimiter->consume('trusted');
        $rateLimiter->consume('other-trusted');

        $this->expectNotToPerformAssertions();
    }
}
