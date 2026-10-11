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

        $rateLimiter->assertWithinQuota('203.0.113.10');
        $rateLimiter->record('203.0.113.10');
        $rateLimiter->assertWithinQuota('203.0.113.10');
        $rateLimiter->record('203.0.113.10');

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsSubmissionsBeyondTheLimitForTheSameClient(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->record('203.0.113.10');

        $this->expectException(ContactRateLimitExceededException::class);

        $rateLimiter->assertWithinQuota('203.0.113.10');
    }

    public function testItTracksEachClientIdentifierIndependently(): void
    {
        $rateLimiter = $this->createRateLimiter(1);

        $rateLimiter->record('203.0.113.10');
        $rateLimiter->assertWithinQuota('203.0.113.20');

        $this->expectNotToPerformAssertions();
    }

    /**
     * Issue #485 : la vérification ne consomme rien. Un envoi qui échoue
     * ensuite (broker indisponible) ne coûte rien au visiteur.
     */
    public function testCheckingTheQuotaConsumesNothing(): void
    {
        $rateLimiter = $this->createRateLimiter(1);

        $rateLimiter->assertWithinQuota('203.0.113.10');
        $rateLimiter->assertWithinQuota('203.0.113.10');
        $rateLimiter->assertWithinQuota('203.0.113.10');

        $this->expectNotToPerformAssertions();
    }

    /**
     * Le refus dit quand réessayer : une échéance dans le futur, celle que le
     * Retry-After transmet (issue #273).
     */
    public function testARefusalCarriesAFutureRetryAfter(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->record('203.0.113.10');

        try {
            $rateLimiter->assertWithinQuota('203.0.113.10');
            self::fail('Le quota est épuisé.');
        } catch (ContactRateLimitExceededException $refusal) {
            self::assertGreaterThan(time(), $refusal->retryAfter->getTimestamp());
        }
    }

    /**
     * Enregistré après un envoi réussi : le message est parti, l'enregistrement
     * ne doit jamais le refuser, même si une soumission concurrente a épuisé
     * le quota entre-temps.
     */
    public function testRecordingNeverRefusesAMessageAlreadySent(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->record('203.0.113.10');

        $rateLimiter->record('203.0.113.10');

        $this->expectNotToPerformAssertions();
    }
}
