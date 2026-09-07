<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Application;

use App\Portfolio\Watch\Application\WatchRefresher;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\Service\InstalledVersionMatcher;
use App\Portfolio\Watch\Domain\Service\InstalledVersionResolverInterface;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use App\Portfolio\Watch\Domain\Service\SupportStatusCalculator;
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
    private WatchRefresher $refresher;

    protected function setUp(): void
    {
        $this->productRepository = self::createStub(WatchedProductRepositoryInterface::class);
        $this->releaseCycleSource = self::createStub(ReleaseCycleSourceInterface::class);
        $this->versionResolver = self::createStub(InstalledVersionResolverInterface::class);
        $this->snapshotRepository = $this->createMock(WatchSnapshotRepositoryInterface::class);

        $this->refresher = new WatchRefresher(
            $this->productRepository,
            $this->snapshotRepository,
            $this->releaseCycleSource,
            $this->versionResolver,
            new InstalledVersionMatcher(),
            new SupportStatusCalculator(),
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

        self::assertSame(['phpp'], $report->unknownSlugs);
        self::assertSame([], $report->failedSlugs);
        self::assertSame(2, $report->refreshedCount);
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

        self::assertSame(['nginx'], $report->failedSlugs);
        self::assertSame(1, $report->refreshedCount);
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

        self::assertFalse($report->persisted);
        self::assertSame(0, $report->refreshedCount);
        self::assertSame(['php'], $report->failedSlugs);
    }

    public function testNoWatchedProductWritesNothing(): void
    {
        $this->givenProducts([]);

        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now());

        self::assertFalse($report->persisted);
        self::assertSame(0, $report->refreshedCount);
    }

    public function testADryRunComputesEverythingAndPersistsNothing(): void
    {
        $this->givenProducts([new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.5.9', 0)]);
        $this->releaseCycleSource->method('fetchProduct')->willReturn($this->phpCycles());

        $this->snapshotRepository->expects(self::never())->method('save');

        $report = $this->refresher->refresh($this->now(), dryRun: true);

        self::assertSame(1, $report->refreshedCount);
        self::assertFalse($report->persisted);
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
