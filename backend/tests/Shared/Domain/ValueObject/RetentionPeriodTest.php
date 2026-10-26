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
        yield 'la plus longue qui reste après l\'an 1' => ['2025 years', '0001-09-22 12:00:00'];
        // 10^12 µs = 11 jours 13:46:40.
        yield 'treize chiffres, lus exactement' => ['1000000000000 usec', '2026-09-10 22:13:20'];
    }

    /**
     * Relecture de #710 : un seuil antérieur à l'an 1 s'écrit « -2974-… »,
     * que PostgreSQL refuse ; la commande sortait en trace. Il est refusé ici.
     */
    #[DataProvider('tooLongExpressions')]
    public function testFromStringRejectsAThresholdBeforeYearOne(string $expression): void
    {
        try {
            RetentionPeriod::fromString($expression, $this->now());
            self::fail('InvalidRetentionPeriodException attendue.');
        } catch (InvalidRetentionPeriodException $exception) {
            self::assertStringContainsString('trop longue', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tooLongExpressions(): iterable
    {
        yield 'l\'an 0' => ['2026 years'];
        yield 'un an de trop' => ['2027 years'];
        yield 'des millénaires' => ['5000 years'];
        yield 'neuf chiffres d\'années' => ['999999999 years'];
        yield 'treize chiffres de secondes' => ['9999999999999 seconds'];
    }

    /**
     * Relecture de #710 : PHP lit un nombre exactement jusqu'à 13 chiffres, et
     * toujours de travers au-delà (mesuré : « 9223372036854775807 seconds »
     * donne 36854775807 secondes, « 999999999999999999 days » 9999999999
     * jours). La durée lue n'est pas celle demandée : l'expression est
     * illisible.
     */
    #[DataProvider('numbersPhpMisreads')]
    public function testFromStringRejectsANumberPhpCannotReadExactly(string $expression): void
    {
        try {
            RetentionPeriod::fromString($expression, $this->now());
            self::fail('InvalidRetentionPeriodException attendue.');
        } catch (InvalidRetentionPeriodException $exception) {
            self::assertStringContainsString('illisible', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numbersPhpMisreads(): iterable
    {
        // Lu 5432109876 secondes (172 ans) : accepté en silence, sans la garde.
        yield 'quatorze chiffres' => ['98765432109876 seconds'];
        yield 'plus grand entier' => ['9223372036854775807 seconds'];
        yield 'dix-huit chiffres' => ['999999999999999999 days'];
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

    /**
     * Relecture de #710 : `DateInterval::$f` est un flottant (« 498 usec » y vaut
     * 0.000497999…), que `add()`/`sub()` tronquent à la microseconde inférieure.
     * Recopié tel quel, il décalait le seuil d'une microseconde et faisait
     * prendre la durée pour une date.
     */
    #[DataProvider('fractionsOfASecond')]
    public function testFractionsOfASecondAreKeptToTheMicrosecond(string $expression, string $expectedThreshold): void
    {
        $now = new DateTimeImmutable(self::NOW.'.000000');

        self::assertSame($expectedThreshold, RetentionPeriod::fromString($expression, $now)->threshold()->format('Y-m-d H:i:s.u'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fractionsOfASecond(): iterable
    {
        yield 'microsecondes' => ['498 usec', '2026-09-22 11:59:59.999502'];
        // Arrondie à six décimales, cette fraction reste en deçà de 249 µs.
        yield 'microsecondes que l\'arrondi seul manque' => ['249 usec', '2026-09-22 11:59:59.999751'];
        yield 'millisecondes au-delà d\'une seconde' => ['1009 msec', '2026-09-22 11:59:58.991000'];
        yield 'secondes et microsecondes' => ['1 sec 498 usec', '2026-09-22 11:59:58.999502'];
        yield 'demi-seconde' => ['500 msec', '2026-09-22 11:59:59.500000'];
    }

    /**
     * Relecture de #710 : `app:user:purge-pending-invitations` retranche
     * l'intervalle lui-même, à l'instant de son service. L'intervalle exposé
     * suit les mêmes règles que le seuil : retranché la veille du passage à
     * l'heure d'hiver, « 36 hours » dure 36 heures écoulées.
     */
    public function testTheIntervalFollowsTheSameRulesAsTheThreshold(): void
    {
        $utc = new DateTimeZone('UTC');
        $now = (new DateTimeImmutable('2026-10-25 12:00:00', $utc))->setTimezone(new DateTimeZone('Europe/Paris'));
        $period = RetentionPeriod::fromString('36 hours', $now);

        self::assertSame(36 * 3600, $now->getTimestamp() - $now->sub($period->interval())->getTimestamp());
        self::assertSame($period->threshold()->getTimestamp(), $now->sub($period->interval())->getTimestamp());
    }

    /**
     * Un `DateInterval` est mutable : le modifier ne doit pas changer la durée
     * du Value Object.
     */
    public function testTheIntervalIsACopy(): void
    {
        $period = RetentionPeriod::fromString('30 days', $this->now());

        $period->interval()->d = 1;

        self::assertSame(30, $period->interval()->d);
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

    /**
     * Relecture de #710 : « 1 day -23 hours » retranche un jour du calendrier
     * puis ajoute 23 heures écoulées. Elle durait 1 heure, 2 le jour du passage
     * à l'heure d'hiver, et 0 (refusée) le jour du passage à l'heure d'été :
     * la même option ne doit pas changer de sens selon le jour.
     */
    #[DataProvider('expressionsMixingSigns')]
    public function testFromStringRejectsAnExpressionMixingSigns(string $expression): void
    {
        try {
            RetentionPeriod::fromString($expression, $this->now());
            self::fail('InvalidRetentionPeriodException attendue.');
        } catch (InvalidRetentionPeriodException $exception) {
            self::assertStringContainsString('signes opposés', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expressionsMixingSigns(): iterable
    {
        yield 'jour moins heures' => ['1 day -23 hours'];
        yield 'heures moins un jour' => ['-1 day 25 hours'];
        yield 'mois moins un jour' => ['1 month -1 day'];
        yield 'seconde moins des millisecondes' => ['1 sec -500 msec'];
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
