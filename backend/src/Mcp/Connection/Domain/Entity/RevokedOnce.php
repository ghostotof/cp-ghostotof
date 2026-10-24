<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La révocation d'un code ou d'un jeton de connexion MCP : un instant, posé
 * une fois. Une seconde révocation ne déplace pas la première — l'instant dit
 * quand le jeton est mort, pas quand on l'a constaté de nouveau —, comme
 * `revokeByHash()` le fait en base.
 *
 * Un trait plutôt qu'une superclasse mappée : les trois entités n'ont en
 * commun que cette colonne et ces trois méthodes, aucune n'est l'autre.
 */
trait RevokedOnce
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    public function revoke(DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
