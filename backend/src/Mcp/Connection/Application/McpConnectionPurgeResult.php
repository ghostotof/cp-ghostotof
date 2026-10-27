<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use DateTimeImmutable;

/**
 * Compte-rendu de {@see McpConnectionPurgerInterface::purge()}, destiné à la
 * commande planifiée qui l'affiche : ce n'est pas un concept métier, il n'est
 * jamais persisté.
 *
 * Les connexions par leur UUID seul, jamais par leur compte, leur client ni
 * une empreinte de jeton (#363) : la sortie d'un CronJob est un journal de pod.
 */
final readonly class McpConnectionPurgeResult
{
    /**
     * @param list<string>      $expired   UUID des connexions que cette purge a marquées expirées
     * @param DateTimeImmutable $threshold une connexion révoquée avant cet instant a été supprimée
     */
    public function __construct(
        public array $expired,
        public int $deletedAuthorizationCodes,
        public int $deletedAccessTokens,
        public int $deletedRefreshTokens,
        public int $deletedConnections,
        public DateTimeImmutable $threshold,
    ) {
    }
}
