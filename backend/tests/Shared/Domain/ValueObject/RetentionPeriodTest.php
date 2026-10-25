<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidRetentionPeriodException;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #248 : `new DateTimeImmutable('-'.$olderThan)` acceptait en silence
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

    /**
     * Issue #709 : un nombre de jours se compte en jours du calendrier du
     * fuseau de `$now` (règle de #445). Une fenêtre de 30 jours qui contient un
     * changement d'heure dure donc 721 ou 719 heures écoulées, pas 720.
     */
    #[DataProvider('calendarDaysAcrossClockChanges')]
    public function testDaysAreCountedInTheCalendarOfNowsTimeZone(string $nowUtc, string $expectedThresholdUtc, int $expectedElapsedHours): void
    {
        $utc = new DateTimeZone('UTC');
        $now = (new DateTimeImmutable($nowUtc, $utc))->setTimezone(new DateTimeZone('Europe/Paris'));

        $threshold = RetentionPeriod::fromString('30 days', $now)->threshold();

        self::assertSame($expectedThresholdUtc, $threshold->setTimezone($utc)->format('Y-m-d H:i:s'));
        self::assertSame($expectedElapsedHours * 3600, $now->getTimestamp() - $threshold->getTimestamp());
    }

    /**
     * Instant UTC, seuil UTC attendu, heures écoulées entre les deux.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function calendarDaysAcrossClockChanges(): iterable
    {
        yield 'heure d\'hiver entrée dans la fenêtre' => ['2026-10-25 03:28:00', '2026-09-25 02:28:00', 721];
        yield 'heure d\'été entrée dans la fenêtre' => ['2027-04-10 12:00:00', '2027-03-11 13:00:00', 719];
        yield 'sans changement d\'heure' => ['2026-10-15 12:00:00', '2026-09-15 12:00:00', 720];
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

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
