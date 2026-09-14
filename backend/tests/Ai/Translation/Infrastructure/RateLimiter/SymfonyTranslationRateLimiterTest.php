<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Infrastructure\RateLimiter;

use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use App\Ai\Translation\Infrastructure\RateLimiter\SymfonyTranslationRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Calqué sur SymfonyContactRateLimiterTest, à une différence près qui est
 * tout l'objet du limiteur : la clé est un compte, pas une adresse IP.
 */
final class SymfonyTranslationRateLimiterTest extends TestCase
{
    private function createRateLimiter(int $limit): SymfonyTranslationRateLimiter
    {
        $factory = new RateLimiterFactory(
            ['id' => 'translation_assistant_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new SymfonyTranslationRateLimiter($factory);
    }

    public function testItAcceptsCallsUnderTheLimit(): void
    {
        $rateLimiter = $this->createRateLimiter(2);

        $rateLimiter->consume('super');
        $rateLimiter->consume('super');

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsTheCallBeyondTheLimitWithARetryAfterDate(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->consume('super');

        try {
            $rateLimiter->consume('super');
            self::fail('Une exception était attendue.');
        } catch (TranslationRateLimitExceededException $exception) {
            self::assertGreaterThan(new \DateTimeImmutable(), $exception->retryAfter);
        }
    }

    public function testItTracksEachAccountIndependently(): void
    {
        $rateLimiter = $this->createRateLimiter(1);

        $rateLimiter->consume('super');
        $rateLimiter->consume('other-super');

        $this->expectNotToPerformAssertions();
    }
}
