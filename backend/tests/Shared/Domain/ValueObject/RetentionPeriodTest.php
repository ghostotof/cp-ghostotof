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

    /**
     * Issue #710 : une durée en heures, minutes ou secondes se compte en temps
     * écoulé (règle de #445), même quand elle enjambe un changement d'heure.
     * `DateInterval::createFromDateString()` la comptait en heure murale du
     * fuseau de `$now` : « 12 hours » durait 13 heures en automne, 11 au
     * printemps. Dans une expression mixte, les jours restent des jours du
     * calendrier, retranchés avant les heures.
     */
    #[DataProvider('elapsedTimeAcrossClockChanges')]
    public function testHoursMinutesAndSecondsAreCountedAsElapsedTime(string $nowUtc, string $expression, string $expectedThresholdUtc, int $expectedElapsedHours): void
    {
        $utc = new DateTimeZone('UTC');
        $now = (new DateTimeImmutable($nowUtc, $utc))->setTimezone(new DateTimeZone('Europe/Paris'));

        $threshold = RetentionPeriod::fromString($expression, $now)->threshold();

        self::assertSame($expectedThresholdUtc, $threshold->setTimezone($utc)->format('Y-m-d H:i:s'));
        self::assertSame($expectedElapsedHours * 3600, $now->getTimestamp() - $threshold->getTimestamp());
    }

    /**
     * Instant UTC, expression, seuil UTC attendu, heures écoulées entre les deux.
     * Changements d'heure : 2026-10-25 01:00 UTC (hiver), 2027-03-28 01:00 UTC (été).
     *
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function elapsedTimeAcrossClockChanges(): iterable
    {
        yield '12 heures, heure d\'hiver entrée dans la fenêtre' => ['2026-10-25 12:00:00', '12 hours', '2026-10-25 00:00:00', 12];
        yield '12 heures, heure d\'été entrée dans la fenêtre' => ['2027-03-28 12:00:00', '12 hours', '2027-03-28 00:00:00', 12];
        yield 'minutes, heure d\'hiver entrée dans la fenêtre' => ['2026-10-25 12:00:00', '720 minutes', '2026-10-25 00:00:00', 12];
        yield 'secondes, heure d\'été entrée dans la fenêtre' => ['2027-03-28 12:00:00', '43200 seconds', '2027-03-28 00:00:00', 12];
        // 2026-10-25 13:00 CET − 1 jour = 2026-10-24 13:00 CEST (25 h), puis − 12 h écoulées.
        yield 'jour puis heures, heure d\'hiver' => ['2026-10-25 12:00:00', '1 day 12 hours', '2026-10-23 23:00:00', 37];
        // 2027-03-28 14:00 CEST − 1 jour = 2027-03-27 14:00 CET (23 h), puis − 12 h écoulées.
        yield 'jour puis heures, heure d\'été' => ['2027-03-28 12:00:00', '1 day 12 hours', '2027-03-27 01:00:00', 35];
        yield 'sans changement d\'heure' => ['2026-10-15 12:00:00', '12 hours', '2026-10-15 00:00:00', 12];
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

    /**
     * Issue #710 : une expression qui désigne une date (jour de la semaine,
     * « first/last … of », heure du jour) n'est pas une durée. `sub()` levait
     * une erreur native sur certaines (« monday », « 3 weekdays »), qui
     * traversait la commande ; d'autres se réduisaient en silence à leurs
     * champs (« last day of next month » purgeait à un mois). Elles sont
     * refusées, avec un message qui le dit, quel que soit l'instant.
     */
    #[DataProvider('expressionsDesignatingADate')]
    public function testFromStringRejectsAnExpressionThatDesignatesADate(string $expression, string $nowInParis): void
    {
        try {
            RetentionPeriod::fromString($expression, new DateTimeImmutable($nowInParis, new DateTimeZone('Europe/Paris')));
            self::fail('InvalidRetentionPeriodException attendue.');
        } catch (InvalidRetentionPeriodException $exception) {
            self::assertStringContainsString('pas une durée', $exception->getMessage());
            self::assertStringContainsString($expression, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expressionsDesignatingADate(): iterable
    {
        yield 'jour de la semaine' => ['monday', self::NOW];
        // Le jour de la semaine de l'instant de référence : vu parce qu'il ramène à minuit.
        yield 'jour de la semaine de la référence' => ['wednesday', self::NOW];
        yield 'jours ouvrés' => ['3 weekdays', self::NOW];
        yield 'premier lundi du mois suivant' => ['first monday of next month', self::NOW];
        yield 'dernier jour du mois suivant' => ['last day of next month', self::NOW];
        // Un 1er du mois : un verdict pris sur `$now` la laisserait passer.
        yield 'premier jour du mois suivant, un 1er du mois' => ['first day of next month', '2026-10-01 12:00:00'];
        yield 'heure du jour' => ['noon tomorrow', self::NOW];
        yield 'hier, ramené à minuit' => ['yesterday', self::NOW];
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
