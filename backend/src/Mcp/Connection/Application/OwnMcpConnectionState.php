<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

/**
 * L'état d'une connexion MCP tel que la personne le lit dans « Mes
 * connexions MCP » (#650). Trois mots stables, publiés tels quels par
 * l'API ; la règle qui les décide est
 * {@see OwnMcpConnections::stateOf()}, une seule.
 */
enum OwnMcpConnectionState: string
{
    /** Ni révoquée ni échue : le client peut encore s'en servir. */
    case Active = 'active';

    /**
     * Échue : la purge l'a marquée (cause `expired`), ou son échéance
     * ({@see \App\Mcp\Connection\Domain\Entity\McpConnection::deadline()}) est
     * passée sans que la purge ait encore tourné.
     */
    case Expired = 'expired';

    /** Coupée avant son échéance, par la personne, le client ou le serveur : la cause dit laquelle. */
    case Revoked = 'revoked';
}
