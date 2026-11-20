<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use DateTimeImmutable;

/**
 * Une connexion terminée et l'instant où elle a **réellement** pris fin, calculé
 * une fois (jamais à chaque comparaison d'un tri) par
 * {@see AdministeredMcpConnections} : la liste des terminées se classe sur cet
 * instant, pas sur le `revoked_at` que la purge pose à son propre passage.
 */
final readonly class EndedMcpConnection
{
    public function __construct(
        public McpConnection $connection,
        public DateTimeImmutable $endedAt,
    ) {
    }
}
