<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Le curseur de la lecture par pages des expirées échangées (#652) : la clé de
 * reprise `(COALESCE(last_used_at, granted_at), id)` de la dernière ligne lue,
 * après laquelle la page suivante reprend (activité décroissante, `id`
 * décroissant).
 *
 * L'activité, pas l'instant de révocation : une purge marque beaucoup de lignes
 * au même `revoked_at`, ce qui ne permettait aucun arrêt anticipé ; l'activité
 * borne la fin de la connexion par le haut (voir `AdministeredMcpConnections`).
 * Un curseur plutôt qu'une connexion : l'activité y est non nulle par le type
 * (l'octroi existe toujours), sans que l'appelant ait à la reconstituer.
 */
final readonly class ExchangedExpiredCursor
{
    public function __construct(
        public DateTimeImmutable $activity,
        public Uuid $id,
    ) {
    }

    /** Le curseur posé sur `$lastRead`, la dernière connexion d'une page. */
    public static function after(McpConnection $lastRead): self
    {
        return new self($lastRead->lastUsedAt() ?? $lastRead->grantedAt(), $lastRead->getId());
    }
}
