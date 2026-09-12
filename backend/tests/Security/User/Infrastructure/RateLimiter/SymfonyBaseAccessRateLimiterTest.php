<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\RateLimiter;

use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use App\Security\User\Infrastructure\RateLimiter\SymfonyBaseAccessRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SymfonyBaseAccessRateLimiterTest extends TestCase
{
    private function createRateLimiter(int $limit): SymfonyBaseAccessRateLimiter
    {
        $factory = new RateLimiterFactory(
            ['id' => 'base_access_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new SymfonyBaseAccessRateLimiter($factory);
    }

    public function testItAcceptsRequestsUnderTheLimit(): void
    {
        $rateLimiter = $this->createRateLimiter(2);

        $rateLimiter->consume('203.0.113.10');
        $rateLimiter->consume('203.0.113.10');

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsRequestsBeyondTheLimitForTheSameClient(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->consume('203.0.113.10');

        $this->expectException(BaseAccessRateLimitExceededException::class);

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
