<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Révoque une connexion MCP et l'audite (#649) : le seul chemin par lequel le
 * code applicatif révoque une connexion — un rafraîchissement réutilisé, un
 * code rejoué, un jeton révoqué par le client, un accord remplacé,
 * l'expiration constatée par la purge.
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
