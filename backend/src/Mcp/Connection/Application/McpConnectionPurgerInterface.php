<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Exception\McpPurgeRetentionTooShortException;
use App\Shared\Domain\ValueObject\RetentionPeriod;

/**
 * Cas d'usage « purger les connexions MCP et leurs jetons morts » (#649),
 * lancé chaque nuit par `app:mcp:purge` :
 *
 * 1. chaque connexion active que McpConnection::isExpiredAt() dit expirée est
 *    révoquée, cause `expired`, une seule fois, et auditée (acteur `system`)
 *    après le COMMIT ;
 * 2. les codes et jetons échus, et tous ceux d'une connexion révoquée, sont
 *    supprimés ;
 * 3. une connexion révoquée (ou expirée) avant la rétention est supprimée.
 *
 * Pas de simulation (`--dry-run`) : elle ne supprime que des jetons morts et
 * des connexions mortes depuis des mois, rien qu'une personne pourrait
 * regretter (décision 6 de #649).
 */
interface McpConnectionPurgerInterface
{
    /**
     * @throws McpPurgeRetentionTooShortException si la rétention est inférieure à un jour, avant toute écriture
     */
    public function purge(RetentionPeriod $retention): McpConnectionPurgeResult;
}
