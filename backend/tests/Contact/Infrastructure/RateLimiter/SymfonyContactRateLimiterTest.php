<?php

declare(strict_types=1);

namespace App\Tests\Contact\Infrastructure\RateLimiter;

use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use App\Contact\Infrastructure\RateLimiter\SymfonyContactRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SymfonyContactRateLimiterTest extends TestCase
{
    private function createRateLimiter(int $limit): SymfonyContactRateLimiter
    {
        $factory = new RateLimiterFactory(
            ['id' => 'contact_form_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new SymfonyContactRateLimiter($factory);
    }

    public function testItAcceptsSubmissionsUnderTheLimit(): void
    {
        $rateLimiter = $this->createRateLimiter(2);

        $rateLimiter->consume('203.0.113.10');
        $rateLimiter->consume('203.0.113.10');

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsSubmissionsBeyondTheLimitForTheSameClient(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->consume('203.0.113.10');

        $this->expectException(ContactRateLimitExceededException::class);

        $rateLimiter->consume('203.0.113.10');
    }

    public function testItTracksEachClientIdentifierIndependently(): void
    {
        $rateLimiter = $this->createRateLimiter(1);

        $rateLimiter->consume('203.0.113.10');
        $rateLimiter->consume('203.0.113.20');

        $this->expectNotToPerformAssertions();
    }
}
