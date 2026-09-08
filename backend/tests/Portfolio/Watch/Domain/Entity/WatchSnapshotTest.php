<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Domain\Entity;

use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Exception\EmptySnapshotPayloadException;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotSourceStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use PHPUnit\Framework\TestCase;

final class WatchSnapshotTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function snapshot(array $payload = ['products' => [['slug' => 'php']]]): WatchSnapshot
    {
        return new WatchSnapshot(
            WatchSnapshotType::RELEASE_CYCLES,
            $payload,
            new \DateTimeImmutable('2026-09-07 04:41:00'),
            SnapshotSourceStatus::OK,
        );
    }

    public function testConstructorSetsAllProperties(): void
    {
        $snapshot = $this->snapshot();

        self::assertNull($snapshot->getId());
        self::assertSame(WatchSnapshotType::RELEASE_CYCLES, $snapshot->getType());
        self::assertSame(['products' => [['slug' => 'php']]], $snapshot->getPayload());
        self::assertSame('2026-09-07 04:41:00', $snapshot->getRefreshedAt()->format('Y-m-d H:i:s'));
        self::assertSame(SnapshotSourceStatus::OK, $snapshot->getSourceStatus());
    }

    public function testRefreshReplacesThePayloadAndTheTimestamp(): void
    {
        $snapshot = $this->snapshot();

        $snapshot->refresh(
            ['products' => [['slug' => 'symfony']]],
            new \DateTimeImmutable('2026-09-08 04:41:00'),
            SnapshotSourceStatus::PARTIAL,
        );

        self::assertSame(['products' => [['slug' => 'symfony']]], $snapshot->getPayload());
        self::assertSame('2026-09-08 04:41:00', $snapshot->getRefreshedAt()->format('Y-m-d H:i:s'));
        self::assertSame(SnapshotSourceStatus::PARTIAL, $snapshot->getSourceStatus());
        // Le type identifie le snapshot : il ne change jamais.
        self::assertSame(WatchSnapshotType::RELEASE_CYCLES, $snapshot->getType());
    }

    /**
     * L'invariant central de la fonctionnalité, rendu impossible à contourner
     * plutôt que confié à la vigilance de l'appelant : quand la source externe
     * est injoignable, la donnée précédente doit survivre. Un rafraîchissement
     * qui n'a rien rapporté n'est pas un rafraîchissement — c'est une panne, et
     * elle ne doit pas effacer ce qu'on savait hier.
     *
     * Voir §9 « Jamais » de la spécification.
     */
    public function testAValidSnapshotIsNeverOverwrittenByAnEmptyPayload(): void
    {
        $snapshot = $this->snapshot();

        try {
            $snapshot->refresh([], new \DateTimeImmutable('2026-09-08 04:41:00'), SnapshotSourceStatus::OK);
            self::fail('Un payload vide aurait dû être refusé.');
        } catch (EmptySnapshotPayloadException) {
            // Et surtout : l'état précédent est intact.
            self::assertSame(['products' => [['slug' => 'php']]], $snapshot->getPayload());
            self::assertSame('2026-09-07 04:41:00', $snapshot->getRefreshedAt()->format('Y-m-d H:i:s'));
        }
    }

    public function testASnapshotCannotBeCreatedEmptyEither(): void
    {
        $this->expectException(EmptySnapshotPayloadException::class);

        $this->snapshot([]);
    }
}
