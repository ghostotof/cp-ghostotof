<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Get;
use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotSourceStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use App\Portfolio\Watch\Infrastructure\ApiPlatform\WatchProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class WatchProviderTest extends TestCase
{
    private WatchSnapshotRepositoryInterface&Stub $snapshotRepository;
    private WatchProvider $provider;

    protected function setUp(): void
    {
        $this->snapshotRepository = self::createStub(WatchSnapshotRepositoryInterface::class);
        $this->provider = new WatchProvider($this->snapshotRepository);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function givenSnapshot(array $payload): void
    {
        $this->snapshotRepository->method('findOneByType')->willReturn(new WatchSnapshot(
            WatchSnapshotType::RELEASE_CYCLES,
            $payload,
            new \DateTimeImmutable('2026-09-07 04:41:00', new \DateTimeZone('UTC')),
            SnapshotSourceStatus::OK,
        ));
    }

    /**
     * Aucun rafraîchissement n'a encore abouti : c'est un état affichable, pas
     * une erreur. Répondre 404 obligerait le frontend à traiter une absence de
     * donnée comme une panne, et une installation neuve ressemblerait à un
     * incident.
     */
    public function testAMissingSnapshotYieldsAnEmptyButValidResource(): void
    {
        $this->snapshotRepository->method('findOneByType')->willReturn(null);

        $resource = $this->provider->provide(new Get());

        self::assertSame([], $resource->releaseCycles->products);
        self::assertNull($resource->releaseCycles->refreshedAt);
        self::assertNull($resource->releaseCycles->sourceStatus);
    }

    public function testItExposesTheStoredEntries(): void
    {
        $this->givenSnapshot(['products' => [[
            'slug' => 'php',
            'label' => 'PHP',
            'version' => '8.5.9',
            'status' => 'supported',
            'cycle' => '8.5',
            'endOfActiveSupportFrom' => '2027-12-31',
            'eolFrom' => '2029-12-31',
            'latestVersion' => '8.5.10',
            'hasNewerPatch' => true,
            'documentationUrl' => 'https://endoflife.date/php',
        ]]]);

        $resource = $this->provider->provide(new Get());

        self::assertCount(1, $resource->releaseCycles->products);
        self::assertSame('php', $resource->releaseCycles->products[0]->slug);
        self::assertSame('8.5.9', $resource->releaseCycles->products[0]->version);
        self::assertSame('supported', $resource->releaseCycles->products[0]->status);
        self::assertTrue($resource->releaseCycles->products[0]->hasNewerPatch);
        self::assertSame('2026-09-07T04:41:00+00:00', $resource->releaseCycles->refreshedAt);
        self::assertSame('ok', $resource->releaseCycles->sourceStatus);
    }

    /**
     * Le payload est du JSON : même écrit par nous, il est relu comme une
     * donnée non fiable. Une entrée amputée de son slug est ignorée plutôt que
     * de faire tomber la page entière.
     */
    public function testAnUnusableEntryIsSkippedRatherThanFatal(): void
    {
        $this->givenSnapshot(['products' => [
            ['label' => 'sans slug'],
            ['slug' => 'php', 'label' => 'PHP', 'status' => 'supported'],
        ]]);

        $resource = $this->provider->provide(new Get());

        self::assertCount(1, $resource->releaseCycles->products);
        self::assertSame('php', $resource->releaseCycles->products[0]->slug);
    }

    public function testAPayloadWithoutProductsYieldsNoEntry(): void
    {
        $this->givenSnapshot(['unexpected' => true]);

        $resource = $this->provider->provide(new Get());

        self::assertSame([], $resource->releaseCycles->products);
        // La date de rafraîchissement reste exposée : le snapshot existe bien.
        self::assertNotNull($resource->releaseCycles->refreshedAt);
    }
}
