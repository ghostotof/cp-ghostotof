<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\ConnectionNeverRevokedException;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Le curseur de la lecture par pages des expirées échangées (#652) : la clé de
 * reprise `(revoked_at, id)` de la dernière ligne lue, après laquelle la page
 * suivante reprend (`revoked_at` décroissant, `id` décroissant).
 *
 * Un curseur plutôt qu'une connexion : l'instant de révocation y est non nul par
 * le type, là où une `McpConnection` le porte nullable et obligeait chaque
 * appelant à se défendre d'un cas que la lecture ne produit pas.
 */
final readonly class ExchangedExpiredCursor
{
    public function __construct(
        public DateTimeImmutable $revokedAt,
        public Uuid $id,
    ) {
    }

    /**
     * Le curseur posé sur `$lastRead`, la dernière connexion d'une page.
     *
     * @throws ConnectionNeverRevokedException si la connexion n'a pas de `revoked_at`
     */
    public static function after(McpConnection $lastRead): self
    {
        $revokedAt = $lastRead->revokedAt();
        if (null === $revokedAt) {
            throw ConnectionNeverRevokedException::noRevocationInstant();
        }

        return new self($revokedAt, $lastRead->getId());
    }
}
