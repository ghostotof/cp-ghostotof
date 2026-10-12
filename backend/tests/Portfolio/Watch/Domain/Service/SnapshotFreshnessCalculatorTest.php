<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\Service\SnapshotFreshnessCalculator;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotFreshness;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnapshotFreshnessCalculatorTest extends TestCase
{
    private const string NOW = '2026-09-07 12:00:00';

    private SnapshotFreshnessCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SnapshotFreshnessCalculator();
    }

    private function freshnessAfter(string $modifier): SnapshotFreshness
    {
        $now = new DateTimeImmutable(self::NOW);

        return $this->calculator->freshnessFor($now->modify($modifier), $now);
    }

    public function testAnAbsentSnapshotHasNeverBeenRefreshed(): void
    {
        self::assertSame(
            SnapshotFreshness::NEVER_REFRESHED,
            $this->calculator->freshnessFor(null, new DateTimeImmutable(self::NOW)),
        );
    }

    public function testARecentSnapshotIsFresh(): void
    {
        self::assertSame(SnapshotFreshness::FRESH, $this->freshnessAfter('-2 hours'));
    }

    /**
     * Le lendemain d'une exécution quotidienne : encore frais, sans quoi
     * l'avertissement s'afficherait chaque jour dans l'heure précédant le
     * rafraîchissement suivant.
     */
    public function testASnapshotFromYesterdayIsStillFresh(): void
    {
        self::assertSame(SnapshotFreshness::FRESH, $this->freshnessAfter('-25 hours'));
    }

    /**
     * Cas limite du seuil : à 35 h 59 c'est encore bon, à 36 h pile ce ne l'est
     * plus. Un cycle manqué est toléré, deux ne le sont pas.
     */
    public function testTheThresholdIsExactlyThirtySixHours(): void
    {
        self::assertSame(SnapshotFreshness::FRESH, $this->freshnessAfter('-35 hours -59 minutes'));
        self::assertSame(SnapshotFreshness::STALE, $this->freshnessAfter('-36 hours'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eveOfAClockChange(): iterable
    {
        yield 'veille du passage à l\'heure d\'hiver' => ['2026-10-24 12:00:00'];
        yield 'veille du passage à l\'heure d\'été' => ['2026-03-28 12:00:00'];
    }

    /**
     * Issue #445 : le seuil compte des heures écoulées, même quand une heure
     * murale disparaît ou se répète entre le rafraîchissement et la lecture
     * (`modify('+36 hours')` donnait 37 h ou 35 h à Paris).
     */
    #[DataProvider('eveOfAClockChange')]
    public function testTheThresholdCountsElapsedHoursAcrossAClockChange(string $refreshedAt): void
    {
        $refreshed = new DateTimeImmutable($refreshedAt, new DateTimeZone('Europe/Paris'));
        $threshold = new DateInterval(\sprintf('PT%dH', SnapshotFreshnessCalculator::STALE_AFTER_HOURS));

        self::assertSame(SnapshotFreshness::FRESH, $this->calculator->freshnessFor($refreshed, $refreshed->add($threshold)->modify('-1 second')));
        self::assertSame(SnapshotFreshness::STALE, $this->calculator->freshnessFor($refreshed, $refreshed->add($threshold)));
    }

    public function testAnOldSnapshotIsStale(): void
    {
        self::assertSame(SnapshotFreshness::STALE, $this->freshnessAfter('-3 days'));
    }

    /**
     * Le seuil doit rester strictement supérieur à la période du travail
     * planifié, faute de quoi tout snapshot serait déclaré périmé juste avant
     * chaque exécution. Ce test fige l'intention plutôt que la valeur.
     */
    public function testTheThresholdLeavesRoomForOneMissedRun(): void
    {
        self::assertGreaterThan(24, SnapshotFreshnessCalculator::STALE_AFTER_HOURS);
    }
}
