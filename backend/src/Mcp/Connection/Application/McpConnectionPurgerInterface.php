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
 * 2. une connexion révoquée (ou expirée) avant la rétention est supprimée,
 *    ses codes et jetons avec elle (la cascade) — avant tout jeton, pour que
 *    les lignes de connexion soient verrouillées avant une ligne de jeton ;
 * 3. les codes, puis les jetons d'accès, puis les jetons de rafraîchissement
 *    échus, et tous ceux d'une connexion révoquée, sont supprimés.
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
