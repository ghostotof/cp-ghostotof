<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Révoque une connexion MCP et l'audite (#649), **hors transaction** : le
 * chemin du code applicatif qui révoque une connexion après une transaction
 * annulée — un rafraîchissement réutilisé, un code rejoué, que league refuse
 * dans la transaction du point de jeton (TokenEndpoint).
 *
 * Dans une transaction, ce révocateur ne convient pas : son audit suivrait
 * l'écriture avant le COMMIT, et survivrait à un ROLLBACK. Là, le code appelle
 * la méthode de dépôt dédiée
 * ({@see \App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface::revokeActive()}),
 * fait rendre à la transaction la {@see RevokedConnection}, et écrit l'audit
 * après le COMMIT — ainsi la révocation d'un rafraîchissement par le client
 * ({@see \App\Mcp\Connection\Infrastructure\League\LeagueRevocationEndpoint}).
 */
interface McpConnectionRevokerInterface
{
    /**
     * Révoque la connexion à l'instant de l'horloge, avec sa cause, puis écrit
     * la ligne d'audit `mcp-connection-revoked` — seulement si la révocation a
     * eu lieu : une connexion inconnue ou déjà révoquée rend `null` et ne
     * laisse aucune ligne (la première révocation a déjà la sienne).
     *
     * **Hors transaction**, une instruction : appelée après une transaction
     * annulée, EntityManager fermé compris (league refuse alors le jeton ou
     * le code, la révocation doit survivre au ROLLBACK de l'échange).
     */
    public function revoke(Uuid $connectionId, RevocationCause $cause): ?RevokedConnection;
}
