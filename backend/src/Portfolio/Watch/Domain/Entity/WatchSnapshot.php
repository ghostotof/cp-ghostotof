<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Entity;

use App\Portfolio\Watch\Domain\Exception\EmptySnapshotPayloadException;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotSourceStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use App\Portfolio\Watch\Infrastructure\Doctrine\WatchSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le résultat, figé en base, du dernier rafraîchissement réussi d'une source
 * externe. Il en existe **un seul par type** (contrainte d'unicité) : c'est
 * l'état courant de la veille, pas un journal.
 *
 * Sa raison d'être est de sortir l'appel sortant du chemin de rendu (décision
 * D5) : l'API publique lit ce snapshot et rien d'autre, si bien qu'une panne
 * d'endoflife.date ou d'OSV.dev ne peut ni ralentir ni casser la page.
 *
 * Le payload est stocké en JSON parce qu'il n'est jamais requêté champ par
 * champ : il est écrit d'un bloc par le rafraîchisseur et relu d'un bloc par le
 * provider. Lui donner un schéma relationnel n'apporterait aucune requête utile
 * et coûterait une jointure à chaque affichage.
 */
#[ORM\Entity(repositoryClass: WatchSnapshotRepository::class)]
#[ORM\Table(name: 'watch_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_watch_snapshot_type', columns: ['type'])]
class WatchSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: WatchSnapshotType::class, length: 30)]
    private WatchSnapshotType $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    /** Date de la donnée elle-même, pas de sa lecture par le visiteur. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $refreshedAt;

    #[ORM\Column(enumType: SnapshotSourceStatus::class, length: 10)]
    private SnapshotSourceStatus $sourceStatus;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws EmptySnapshotPayloadException si le payload est vide
     */
    public function __construct(
        WatchSnapshotType $type,
        array $payload,
        \DateTimeImmutable $refreshedAt,
        SnapshotSourceStatus $sourceStatus,
    ) {
        $this->assertPayloadIsNotEmpty($type, $payload);

        $this->type = $type;
        $this->payload = $payload;
        $this->refreshedAt = $refreshedAt;
        $this->sourceStatus = $sourceStatus;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): WatchSnapshotType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getRefreshedAt(): \DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    public function getSourceStatus(): SnapshotSourceStatus
    {
        return $this->sourceStatus;
    }

    /**
     * Remplace l'état courant. Le type n'est pas modifiable : il identifie le
     * snapshot.
     *
     * @param array<string, mixed> $payload
     *
     * @throws EmptySnapshotPayloadException si le payload est vide — auquel cas
     *                                       l'état précédent reste intact
     */
    public function refresh(
        array $payload,
        \DateTimeImmutable $refreshedAt,
        SnapshotSourceStatus $sourceStatus,
    ): void {
        $this->assertPayloadIsNotEmpty($this->type, $payload);

        $this->payload = $payload;
        $this->refreshedAt = $refreshedAt;
        $this->sourceStatus = $sourceStatus;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws EmptySnapshotPayloadException
     */
    private function assertPayloadIsNotEmpty(WatchSnapshotType $type, array $payload): void
    {
        if ([] === $payload) {
            throw EmptySnapshotPayloadException::forType($type);
        }
    }
}
