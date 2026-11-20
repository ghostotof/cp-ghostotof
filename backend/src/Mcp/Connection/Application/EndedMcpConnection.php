<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use DateTimeImmutable;

/**
 * Une connexion terminée, l'instant où elle a **réellement** pris fin et l'échéance à
 * afficher, calculés une fois (jamais à chaque comparaison d'un tri) et par la même
 * méthode de {@see AdministeredMcpConnections} : la liste des terminées se classe sur cet
 * instant, pas sur le `revoked_at` que la purge pose à son propre passage.
 *
 * `$displayedDeadline` `null` signifie « celle de {@see McpConnectionStatus::expiresAt()} »
 * (les révoquées pour une autre cause, dont l'affichage reste un point ouvert, et les
 * jamais échangées).
 */
final readonly class EndedMcpConnection
{
    public function __construct(
        public McpConnection $connection,
        public DateTimeImmutable $endedAt,
        public ?DateTimeImmutable $displayedDeadline,
    ) {
    }
}
