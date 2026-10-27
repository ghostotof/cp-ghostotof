<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Exception\CompromisedMcpConnectionException;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;

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
     * Révoque la connexion que la compromission désigne, à l'instant de
     * l'horloge, avec sa cause (`refresh-token-reuse` ou `code-replay`), puis
     * écrit la ligne d'audit `mcp-connection-revoked` — seulement si la
     * révocation a eu lieu : une connexion inconnue ou déjà révoquée rend
     * `null` et ne laisse aucune ligne (la première révocation a déjà la
     * sienne).
     *
     * **Hors transaction**, une instruction : appelée après une transaction
     * annulée, EntityManager fermé compris (league refuse alors le jeton ou
     * le code, la révocation doit survivre au ROLLBACK de l'échange).
     *
     * Typé par la compromission (#726, CR6) : une cause née ailleurs —
     * l'expiration, le remplacement, le client — ne peut pas s'y demander.
     */
    public function revoke(CompromisedMcpConnectionException $compromise): ?RevokedConnection;
}
