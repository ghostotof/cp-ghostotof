<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidRetentionPeriodException;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #248 : `new \DateTimeImmutable('-'.$olderThan)` acceptait en silence
 * une double négation ("-30 days" -> '--30 days', interprété comme "+30
 * days") et plaçait le seuil de purge dans le futur. `RetentionPeriod` répare
 * ça en comparant le seuil obtenu à `$now`, qui est le seul invariant qui
 * couvre à la fois le signe négatif, la durée nulle et une expression déjà
 * tournée vers le futur ("2 days ago").
 */
final class RetentionPeriodTest extends TestCase
{
    private const string NOW = '2026-09-22 12:00:00';

    #[DataProvider('acceptedExpressions')]
    public function testFromStringComputesTheExpectedThreshold(string $expression, string $expectedThreshold): void
    {
        $period = RetentionPeriod::fromString($expression, $this->now());

        self::assertSame($expectedThreshold, $period->threshold()->format('Y-m-d H:i:s'));
        self::assertSame(trim($expression), $period->expression());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedExpressions(): iterable
    {
        yield '30 jours' => ['30 days', '2026-08-23 12:00:00'];
        yield '12 heures' => ['12 hours', '2026-09-22 00:00:00'];
        yield 'signe + explicite' => ['+30 days', '2026-08-23 12:00:00'];
    }

    #[DataProvider('rejectedAsNotStrictlyPositive')]
    public function testFromStringRejectsAnExpressionThatDoesNotYieldAPastThreshold(string $expression): void
    {
        $this->expectException(InvalidRetentionPeriodException::class);

        RetentionPeriod::fromString($expression, $this->now());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedAsNotStrictlyPositive(): iterable
    {
        yield 'double négation (le défaut #248)' => ['-30 days'];
        yield 'durée nulle' => ['0 days'];
        yield 'expression déjà tournée vers le futur' => ['2 days ago'];
    }

    #[DataProvider('unreadableExpressions')]
    public function testFromStringRejectsAnUnreadableExpression(string $expression): void
    {
        $this->expectException(InvalidRetentionPeriodException::class);

        RetentionPeriod::fromString($expression, $this->now());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableExpressions(): iterable
    {
        yield 'chaîne vide' => [''];
        yield 'espaces seuls' => ['   '];
        yield 'texte quelconque' => ['not-an-interval'];
    }

    /**
     * Le message doit nommer l'expression fautive, pour que l'erreur affichée
     * par la commande reste diagnosticable.
     */
    public function testExceptionMessageNamesTheOffendingExpression(): void
    {
        try {
            RetentionPeriod::fromString('-30 days', $this->now());
            self::fail('InvalidRetentionPeriodException attendue.');
        } catch (InvalidRetentionPeriodException $exception) {
            self::assertStringContainsString('-30 days', $exception->getMessage());
        }
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
