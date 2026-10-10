<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Infrastructure\RateLimiter;

use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use App\Ai\Translation\Infrastructure\RateLimiter\SymfonyTranslationRateLimiter;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Calqué sur SymfonyContactRateLimiterTest, à une différence près qui est
 * tout l'objet du limiteur : la clé est un compte, pas une adresse IP.
 */
final class SymfonyTranslationRateLimiterTest extends TestCase
{
    private TestHandler $aiUsage;

    protected function setUp(): void
    {
        $this->aiUsage = new TestHandler();
    }

    private function createRateLimiter(int $limit): SymfonyTranslationRateLimiter
    {
        $factory = new RateLimiterFactory(
            ['id' => 'translation_assistant_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new SymfonyTranslationRateLimiter($factory, new Logger('ai_usage', [$this->aiUsage]));
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
            self::assertGreaterThan(new DateTimeImmutable(), $exception->retryAfter);
        }
    }

    public function testItTracksEachAccountIndependently(): void
    {
        $rateLimiter = $this->createRateLimiter(1);

        $rateLimiter->consume('super');
        $rateLimiter->consume('other-super');

        $this->expectNotToPerformAssertions();
    }

    /**
     * Issue #356 : un refus se trace sur `ai_usage` (niveau fixe en
     * production) avec le compte et l'échéance, comme celui de l'assistant de
     * parcours — le 429 du noyau, en `info` sur le canal applicatif, ne sort
     * jamais d'un pod. Rien du contenu à traduire : le limiteur ne le voit pas.
     */
    public function testARefusalIsLoggedOnAiUsageWithTheAccountAndTheDeadline(): void
    {
        $rateLimiter = $this->createRateLimiter(1);
        $rateLimiter->consume('super');

        try {
            $rateLimiter->consume('super');
            self::fail('Une exception était attendue.');
        } catch (TranslationRateLimitExceededException $exception) {
            $records = $this->aiUsage->getRecords();
            self::assertCount(1, $records);
            self::assertSame(Level::Info, $records[0]->level);
            self::assertSame([
                'outcome' => 'rate-limited',
                'account' => 'super',
                'retryAfter' => $exception->retryAfter->format(\DATE_ATOM),
            ], $records[0]->context);
        }
    }

    public function testAnAcceptedCallLogsNothing(): void
    {
        $this->createRateLimiter(1)->consume('super');

        self::assertSame([], $this->aiUsage->getRecords());
    }
}
