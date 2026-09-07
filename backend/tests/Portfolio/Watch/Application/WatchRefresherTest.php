<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Application;

use App\Portfolio\Watch\Application\WatchRefresher;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Exception\VulnerabilitySourceUnavailableException;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\Service\InstalledVersionMatcher;
use App\Portfolio\Watch\Domain\Service\InstalledVersionResolverInterface;
use App\Portfolio\Watch\Domain\Service\PackageManifestReaderInterface;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use App\Portfolio\Watch\Domain\Service\SupportStatusCalculator;
use App\Portfolio\Watch\Domain\Service\VulnerabilitySourceInterface;
use App\Portfolio\Watch\Domain\ValueObject\KnownVulnerability;
use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Domain\ValueObject\PackageManifest;
use App\Portfolio\Watch\Domain\ValueObject\ProductReleaseCycles;
use App\Portfolio\Watch\Domain\ValueObject\ReleaseCycle;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotSourceStatus;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Les collaborateurs purs (matcher, calculateur) sont utilisés pour de vrai :
 * les doubler ne testerait que le câblage. Seules les entrées/sorties — dépôts,
 * source externe, runtime — sont substituées.
 *
 * Distinction volontaire entre stub et mock : ce qui ne fait que fournir une
 * donnée est un stub, seul le dépôt de snapshots est un mock, parce que ce
 * qu'on veut vérifier de lui n'est pas ce qu'il retourne mais **s'il est
 * appelé** — écrire ou ne pas écrire est ici tout l'enjeu.
 */
final class WatchRefresherTest extends TestCase
{
    private const string NOW = '2026-09-07 04:41:00';

    private WatchedProductRepositoryInterface&Stub $productRepository;
    private ReleaseCycleSourceInterface&Stub $releaseCycleSource;
    private InstalledVersionResolverInterface&Stub $versionResolver;
    private WatchSnapshotRepositoryInterface&MockObject $snapshotRepository;
    private PackageManifestReaderInterface&Stub $packageManifestReader;
    private VulnerabilitySourceInterface&Stub $vulnerabilitySource;
    private WatchRefresher $refresher;

    protected function setUp(): void
    {
        $this->productRepository = self::createStub(WatchedProductRepositoryInterface::class);
        $this->releaseCycleSource = self::createStub(ReleaseCycleSourceInterface::class);
        $this->versionResolver = self::createStub(InstalledVersionResolverInterface::class);
        $this->snapshotRepository = $this->createMock(WatchSnapshotRepositoryInterface::class);
        $this->packageManifestReader = self::createStub(PackageManifestReaderInterface::class);
        $this->vulnerabilitySource = self::createStub(VulnerabilitySourceInterface::class);

        $this->refresher = new WatchRefresher(
            $this->productRepository,
            $this->snapshotRepository,
            $this->releaseCycleSource,
            $this->versionResolver,
            new InstalledVersionMatcher(),
            new SupportStatusCalculator(),
            $this->packageManifestReader,
            $this->vulnerabilitySource,
            new NullLogger(),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function phpCycles(): ProductReleaseCycles
    {
        return new ProductReleaseCycles('php', 'PHP', 'https://endoflife.date/php', [
            new ReleaseCycle(
                '8.5',
                true,
                false,
                new \DateTimeImmutable('2027-12-31'),
                false,
                new \DateTimeImmutable('2029-12-31'),
                '8.5.10',
            ),
        ]);
    }

    /**
     * @param list<WatchedProduct> $products
     */
    private function givenProducts(array $products): void
    {
        $this->productRepository->method('findAllOrdered')->willReturn($products);
    }

    /**
     * @param list<PackageCoordinates> $packages
     */
    private function givenManifest(array $packages): void
    {
        $this->packageManifestReader->method('read')->willReturn(
            new PackageManifest(new \DateTimeImmutable('2026-09-01 00:00:00'), $packages),
        );
    }

    /**
     * @return callable(): WatchSnapshot le snapshot du type demandé, tel qu'il a été enregistré
     */
    private function captureSnapshot(WatchSnapshotType $type): callable
    {
        $captured = null;

        $this->snapshotRepository->method('findOneByType')->willReturn(null);
        $this->snapshotRepository
            ->expects(self::atLeastOnce())
            ->method('save')
            ->willReturnCallback(function (WatchSnapshot $snapshot) use (&$captured, $type): void {
                if ($snapshot->getType() === $type) {
                    $captured = $snapshot;
                }
            });

        return static function () use (&$captured): WatchSnapshot {
            self::assertInstanceOf(WatchSnapshot::class, $captured);

            return $captured;
        };
    }

    /**
     * @return list<array<string, mixed>> les entrées effectivement persistées
     */
    private function capturePersistedProducts(): array
    {
        $captured = [];

        $this->snapshotRepository->method('findOneByType')->willReturn(null);
        $this->snapshotRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (WatchSnapshot $snapshot) use (&$captured): void {
                /** @var list<array<string, mixed>> $products */
                $products = $snapshot->getPayload()['products'];
                $captured = $products;
            });

        $this->refresher->refresh($this->now());

        return $captured;
    }

    public function testItWritesOneEntryPerWatchedProduct(): void
    {
        $this->givenProducts([
            new WatchedProduct('php', 'PHP', VersionSource::RUNTIME_PHP, null, 0),
            new WatchedProduct('postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4', 1),
        ]);
        $this->versionResolver->method('resolve')->willReturn('8.5.9');
        $this->releaseCycleSource->method('fetchProduct')->willReturnCallback(
            fn (string $slug): ProductReleaseCycles => 'php' === $slug
                ? $this->phpCycles()
                : new ProductReleaseCycles('postgresql', 'PostgreSQL', null, [
                    new ReleaseCycle('18', true, false, null, false, new \DateTimeImmutable('2030-11-14'), '18.5'),
                ]),
        );

        self::assertCount(2, $this->capturePersistedProducts());
    }

    /**
     * Décision D2 : la version de PHP vient du processus, celle de PostgreSQL
     * de la saisie.
     *
     * Le résolveur renvoie ici « 8.5.9 » quelle que soit la demande : si le code
     * l'interrogeait à tort pour une source MANUAL, PostgreSQL afficherait
     * « 8.5.9 » au lieu de « 18.4 ». L'assertion suffit donc à prouver que les
     * deux chemins restent distincts, sans compter les appels.
     */
    public function testItUsesTheRuntimeVersionForRuntimeSourcesAndTheStoredOneOtherwise(): void
    {
        $this->givenProducts([
            new WatchedProduct('php', 'PHP', VersionSource::RUNTIME_PHP, null, 0),
            new WatchedProduct('postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4', 1),
        ]);
        $this->versionResolver->method('resolve')->willReturn('8.5.9');
        $this->releaseCycleSource->method('fetchProduct')->willReturnCallback(
            fn (string $slug): ProductReleaseCycles => 'php' === $slug
                ? $this->phpCycles()
                : new ProductReleaseCycles('postgresql', 'PostgreSQL', null, []),
        );

        $products = $this->capturePersistedProducts();

        self::assertSame('8.5.9', $products[0]['version']);
        self::assertSame('18.4', $products[1]['version']);
    }

    public function testItReportsTheComputedStatusAndTheAvailablePatch(): void
    {
        $this->givenProducts([new WatchedProduct('php', 'PHP', VersionSource::RUNTIME_PHP, null, 0)]);
        $this->versionResolver->method('resolve')->willReturn('8.5.9');
        $this->releaseCycleSource->method('fetchProduct')->willReturn($this->phpCycles());

        $products = $this->capturePersistedProducts();

        self::assertSame('supported', $products[0]['status']);
        self::assertSame('8.5', $products[0]['cycle']);
        self::assertSame('2027-12-31', $products[0]['endOfActiveSupportFrom']);
        self::assertSame('2029-12-31', $products[0]['eolFrom']);
        self::assertSame('8.5.10', $products[0]['latestVersion']);
        self::assertTrue($products[0]['hasNewerPatch']);
    }

    /**
     * Un slug mal saisi est une erreur de contenu, réparable au backoffice : il
     * dégrade son entrée en « inconnu » sans faire disparaître les autres, et
     * sans se déclarer panne de source.
     */
    public function testAnUnknownSlugDegradesItsOwnEntryOnly(): void
    {
        $this->givenProducts([
            new WatchedProduct('phpp', 'PHP (typo)', VersionSource::MANUAL, '8.5.9', 0),
            new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.5.9', 1),
        ]);
        $this->releaseCycleSource->method('fetchProduct')->willReturnCallback(
            fn (string $slug): ProductReleaseCycles => 'php' === $slug
                ? $this->phpCycles()
                : throw ReleaseCycleProductNotFoundException::forSlug($slug),
        );

        $capturedStatus = null;
        $this->snapshotRepository->method('findOneByType')->willReturn(null);
        $this->snapshotRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (WatchSnapshot $snapshot) use (&$capturedStatus): void {
                $capturedStatus = $snapshot->getSourceStatus();
            });

        $report = $this->refresher->refresh($this->now());

        self::assertSame(['phpp'], $report->releaseCycles->unknownSlugs);
        self::assertSame([], $report->releaseCycles->failedSlugs);
        self::assertSame(2, $report->releaseCycles->refreshedCount);
        // La source a répondu, et correctement : ce n'est pas une panne.
        self::assertSame(SnapshotSourceStatus::OK, $capturedStatus);
    }

    public function testAFailingSourceMarksTheSnapshotPartial(): void
    {
        $this->givenProducts([
            new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.5.9', 0),
            new WatchedProduct('nginx', 'nginx', VersionSource::MANUAL, '1.30.4', 1),
        ]);
        $this->releaseCycleSource->method('fetchProduct')->willReturnCallback(
            fn (string $slug): ProductReleaseCycles => 'php' === $slug
                ? $this->phpCycles()
                : throw ReleaseCycleSourceUnavailableException::forUnexpectedStatus($slug, 503),
        );

        $capturedStatus = null;
        $this->snapshotRepository->method('findOneByType')->willReturn(null);
        $this->snapshotRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (WatchSnapshot $snapshot) use (&$capturedStatus): void {
                $capturedStatus = $snapshot->getSourceStatus();
            });

        $report = $this->refresher->refresh($this->now());

        self::assertSame(['nginx'], $report->releaseCycles->failedSlugs);
        self::assertSame(1, $report->releaseCycles->refreshedCount);
        self::assertSame(SnapshotSourceStatus::PARTIAL, $capturedStatus);
    }

    /**
     * Le cas qui justifie tout le dispositif : la source est intégralement
     * injoignable. Rien n'est écrit, donc le snapshot de la veille reste servi.
     * Écrire un payload « zéro produit » effacerait la page à la première panne
     * du fournisseur.
     */
    public function testATotalOutageWritesNothingAtAll(): void
    {
        $this->givenProducts([new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.5.9', 0)]);
        $this->releaseCycleSource->method('fetchProduct')->willThrowException(
            ReleaseCycleSourceUnavailableException::forUnexpectedStatus('php', 503),
        );

        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now());

        self::assertFalse($report->releaseCycles->persisted);
        self::assertSame(0, $report->releaseCycles->refreshedCount);
        self::assertSame(['php'], $report->releaseCycles->failedSlugs);
    }

    public function testNoWatchedProductWritesNothing(): void
    {
        $this->givenProducts([]);

        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now());

        self::assertFalse($report->releaseCycles->persisted);
        self::assertSame(0, $report->releaseCycles->refreshedCount);
    }

    public function testADryRunComputesEverythingAndPersistsNothing(): void
    {
        $this->givenProducts([new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.5.9', 0)]);
        $this->releaseCycleSource->method('fetchProduct')->willReturn($this->phpCycles());

        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now(), dryRun: true);

        self::assertSame(1, $report->releaseCycles->refreshedCount);
        self::assertFalse($report->releaseCycles->persisted);
    }

    /**
     * Rejouer le rafraîchissement ne doit pas empiler les snapshots : il n'en
     * existe qu'un par type, mis à jour en place.
     */
    public function testItUpdatesTheExistingSnapshotRatherThanCreatingASecond(): void
    {
        $existing = new WatchSnapshot(
            WatchSnapshotType::RELEASE_CYCLES,
            ['products' => [['slug' => 'obsolete']]],
            new \DateTimeImmutable('2026-09-01 00:00:00'),
            SnapshotSourceStatus::OK,
        );

        $this->givenProducts([new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.5.9', 0)]);
        $this->releaseCycleSource->method('fetchProduct')->willReturn($this->phpCycles());
        $this->snapshotRepository->method('findOneByType')->willReturn($existing);

        $saved = null;
        $this->snapshotRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (WatchSnapshot $snapshot) use (&$saved): void {
                $saved = $snapshot;
            });

        $this->refresher->refresh($this->now());

        self::assertSame($existing, $saved);
        self::assertSame(self::NOW, $existing->getRefreshedAt()->format('Y-m-d H:i:s'));
        /** @var list<array<string, mixed>> $products */
        $products = $existing->getPayload()['products'];
        self::assertSame('php', $products[0]['slug']);
    }

    /**
     * Sans manifeste, l'analyse n'a pas lieu d'être tentée — et surtout, rien
     * n'est écrit. C'est ce qui permettra à la page d'annoncer « analyse non
     * effectuée » plutôt qu'un « 0 vulnérabilité » que personne n'a vérifié.
     */
    public function testWithoutAManifestNoVulnerabilityScanIsAttempted(): void
    {
        $this->givenProducts([]);
        $this->packageManifestReader->method('read')->willReturn(null);
        $this->vulnerabilitySource->method('findVulnerabilities')->willReturnCallback(
            static fn (): never => throw new \LogicException('La base ne doit pas être interrogée sans périmètre.'),
        );

        // Rien à écrire d'aucun côté : ni cycles de vie (aucun produit suivi),
        // ni vulnérabilités (aucun périmètre).
        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now());

        self::assertFalse($report->vulnerabilities->wasAttempted());
        self::assertNull($report->vulnerabilities->packagesScanned);
        self::assertFalse($report->vulnerabilities->persisted);
    }

    public function testItScansThePackagesOfTheManifest(): void
    {
        $this->givenProducts([]);
        $this->givenManifest([
            new PackageCoordinates(PackageCoordinates::ECOSYSTEM_PACKAGIST, 'symfony/http-client', '8.1.4'),
            new PackageCoordinates(PackageCoordinates::ECOSYSTEM_NPM, 'vue', '3.5.42'),
        ]);
        $this->vulnerabilitySource->method('findVulnerabilities')->willReturn([]);

        $captured = $this->captureSnapshot(WatchSnapshotType::VULNERABILITIES);
        $report = $this->refresher->refresh($this->now());

        self::assertSame(2, $report->vulnerabilities->packagesScanned);
        self::assertSame(0, $report->vulnerabilities->found);
        self::assertSame(2, $captured()->getPayload()['packagesScanned']);
    }

    /**
     * Le snapshot conserve le détail complet : c'est le provider public qui
     * n'en expose qu'un décompte (D4). L'écrire amputé priverait le backoffice
     * de ce qu'il est précisément le seul à pouvoir consulter.
     */
    public function testTheSnapshotKeepsTheFullDetailForTheBackoffice(): void
    {
        $package = new PackageCoordinates(PackageCoordinates::ECOSYSTEM_PACKAGIST, 'symfony/http-kernel', '4.0.0');
        $this->givenProducts([]);
        $this->givenManifest([$package]);
        $this->vulnerabilitySource->method('findVulnerabilities')->willReturn([
            new KnownVulnerability('GHSA-aaaa', ['CVE-2026-1'], 'Résumé', 'HIGH', $package, '4.4.50'),
        ]);

        $captured = $this->captureSnapshot(WatchSnapshotType::VULNERABILITIES);
        $report = $this->refresher->refresh($this->now());

        self::assertSame(1, $report->vulnerabilities->found);

        /** @var list<array<string, mixed>> $stored */
        $stored = $captured()->getPayload()['vulnerabilities'];
        self::assertSame('GHSA-aaaa', $stored[0]['id']);
        self::assertSame('HIGH', $stored[0]['severity']);
        self::assertSame('4.4.50', $stored[0]['fixedIn']);
        self::assertSame(['ecosystem' => 'Packagist', 'name' => 'symfony/http-kernel', 'version' => '4.0.0'], $stored[0]['package']);
    }

    /**
     * Une base injoignable n'efface pas la dernière analyse : rien n'est écrit,
     * et l'échec est rapporté pour que le travail planifié reprenne.
     */
    public function testAnUnreachableVulnerabilityDatabaseWritesNothing(): void
    {
        $this->givenProducts([]);
        $this->givenManifest([new PackageCoordinates(PackageCoordinates::ECOSYSTEM_NPM, 'vue', '3.5.42')]);
        $this->vulnerabilitySource->method('findVulnerabilities')->willThrowException(
            VulnerabilitySourceUnavailableException::forUnexpectedStatus(503),
        );

        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now());

        self::assertTrue($report->vulnerabilities->failed);
        self::assertFalse($report->vulnerabilities->persisted);
        self::assertTrue($report->hasFailure());
    }

    public function testADryRunScansWithoutWriting(): void
    {
        $this->givenProducts([]);
        $this->givenManifest([new PackageCoordinates(PackageCoordinates::ECOSYSTEM_NPM, 'vue', '3.5.42')]);
        $this->vulnerabilitySource->method('findVulnerabilities')->willReturn([]);

        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now(), dryRun: true);

        self::assertSame(1, $report->vulnerabilities->packagesScanned);
        self::assertFalse($report->vulnerabilities->persisted);
    }

    /**
     * Deux exécutions consécutives à la même date produisent le même contenu :
     * la commande est rejouable sans effet de bord.
     */
    public function testTwoConsecutiveRunsProduceTheSamePayload(): void
    {
        $this->givenProducts([new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.5.9', 0)]);
        $this->releaseCycleSource->method('fetchProduct')->willReturn($this->phpCycles());
        $this->snapshotRepository->method('findOneByType')->willReturn(null);

        $payloads = [];
        $this->snapshotRepository
            ->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(function (WatchSnapshot $snapshot) use (&$payloads): void {
                $payloads[] = $snapshot->getPayload();
            });

        $this->refresher->refresh($this->now());
        $this->refresher->refresh($this->now());

        self::assertCount(2, $payloads);
        self::assertSame($payloads[0], $payloads[1]);
    }
}
