<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\Service\InstalledVersionMatcher;
use App\Portfolio\Watch\Domain\ValueObject\ProductReleaseCycles;
use App\Portfolio\Watch\Domain\ValueObject\ReleaseCycle;
use PHPUnit\Framework\TestCase;

final class InstalledVersionMatcherTest extends TestCase
{
    private InstalledVersionMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new InstalledVersionMatcher();
    }

    private function cycle(string $name, ?string $latestVersion = null): ReleaseCycle
    {
        return new ReleaseCycle($name, true, false, null, false, null, $latestVersion);
    }

    /**
     * @param list<ReleaseCycle> $cycles
     */
    private function product(array $cycles): ProductReleaseCycles
    {
        return new ProductReleaseCycles('php', 'PHP', null, $cycles);
    }

    public function testItMatchesTheCycleCarryingTheInstalledVersion(): void
    {
        $product = $this->product([$this->cycle('8.5'), $this->cycle('8.4')]);

        self::assertSame('8.5', $this->matcher->matchInstalledVersion($product, '8.5.9')?->name);
    }

    /**
     * Le piège de la comparaison textuelle : « 8.10.2 » *commence* par la
     * chaîne « 8.1 », mais n'appartient évidemment pas au cycle 8.1. Un
     * str_starts_with naïf afficherait ici le statut d'une version en fin de
     * vie pour une version parfaitement supportée — la comparaison se fait donc
     * segment par segment.
     */
    public function testItDoesNotConfuseVersionEightTenWithVersionEightOne(): void
    {
        $product = $this->product([$this->cycle('8.1'), $this->cycle('8.10')]);

        self::assertSame('8.10', $this->matcher->matchInstalledVersion($product, '8.10.2')?->name);
    }

    public function testItMatchesASingleSegmentCycle(): void
    {
        $product = $this->product([$this->cycle('18'), $this->cycle('17')]);

        self::assertSame('18', $this->matcher->matchInstalledVersion($product, '18.4')?->name);
    }

    /**
     * Les versions d'image Docker traînent un suffixe de distribution
     * (`8.5.9-fpm-alpine3.24` dans versions.lock). Le rapprochement doit le
     * tolérer, sinon la moitié de la stack serait affichée « inconnue ».
     */
    public function testItToleratesADistributionSuffix(): void
    {
        $product = $this->product([$this->cycle('8.5')]);

        self::assertSame('8.5', $this->matcher->matchInstalledVersion($product, '8.5.9-fpm-alpine3.24')?->name);
    }

    public function testAVersionOutsideEveryPublishedCycleMatchesNothing(): void
    {
        $product = $this->product([$this->cycle('8.5'), $this->cycle('8.4')]);

        self::assertNull($this->matcher->matchInstalledVersion($product, '7.2.34'));
    }

    public function testAnUnparsableVersionMatchesNothing(): void
    {
        $product = $this->product([$this->cycle('8.5')]);

        self::assertNull($this->matcher->matchInstalledVersion($product, 'dev-main'));
    }

    public function testItDetectsAnAvailablePatch(): void
    {
        self::assertTrue($this->matcher->hasNewerPatch($this->cycle('8.5', '8.5.10'), '8.5.9'));
    }

    /**
     * Deuxième piège numérique : « 8.5.9 » est bien *antérieur* à « 8.5.10 »,
     * alors qu'une comparaison de chaînes conclurait l'inverse.
     */
    public function testItComparesPatchNumbersNumericallyNotAlphabetically(): void
    {
        self::assertFalse($this->matcher->hasNewerPatch($this->cycle('8.5', '8.5.9'), '8.5.10'));
    }

    public function testAnUpToDateInstallationHasNoPatchAvailable(): void
    {
        self::assertFalse($this->matcher->hasNewerPatch($this->cycle('8.5', '8.5.10'), '8.5.10'));
    }

    public function testNoCycleOrNoPublishedPatchMeansNothingToReport(): void
    {
        self::assertFalse($this->matcher->hasNewerPatch(null, '8.5.9'));
        self::assertFalse($this->matcher->hasNewerPatch($this->cycle('8.5'), '8.5.9'));
    }
}
