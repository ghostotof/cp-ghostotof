<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\Service\SnapshotFreshnessCalculator;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotFreshness;
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
        $now = new \DateTimeImmutable(self::NOW);

        return $this->calculator->freshnessFor($now->modify($modifier), $now);
    }

    public function testAnAbsentSnapshotHasNeverBeenRefreshed(): void
    {
        self::assertSame(
            SnapshotFreshness::NEVER_REFRESHED,
            $this->calculator->freshnessFor(null, new \DateTimeImmutable(self::NOW)),
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
