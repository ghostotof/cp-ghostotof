<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

/**
 * Pourquoi une connexion MCP a été révoquée (#649). Écrite une fois, avec
 * l'instant, par la seule révocation du dépôt
 * ({@see \App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface::revokeActive()}),
 * dans la colonne `mcp_connection.revocation_cause` ; la base refuse un instant
 * sans cause et une cause sans instant (`chk_mcp_connection_revocation_cause`).
 *
 * La valeur est un mot stable (kebab), sans rien d'un jeton : elle entre telle
 * quelle dans la ligne d'audit `mcp-connection-revoked` (champ `cause`) et en
 * base, où elle tient en `VARCHAR(32)`. Les trois dernières causes sont celles
 * de #650 (`person`, `password-changed`, `trusted-tier-withdrawn`) : la base ne
 * les admet que depuis `Version20261028120000`, qui passe avant le code qui les
 * écrit (expand/contract, #175).
 */
enum RevocationCause: string
{
    /** Le client a révoqué son jeton de rafraîchissement au point de révocation (RFC 7009 §2.1). */
    case Client = 'client';

    /**
     * Un jeton de rafraîchissement déjà tourné a été présenté de nouveau
     * (OAuth 2.1 §4.3.1) : il a fuité, ou le client rejoue une réponse perdue.
     */
    case RefreshTokenReuse = 'refresh-token-reuse';

    /** Un code d'autorisation déjà échangé a été présenté de nouveau (OAuth 2.1 §4.1.2). */
    case CodeReplay = 'code-replay';

    /** Un nouvel accord du même compte au même client a remplacé celui-ci. */
    case Superseded = 'superseded';

    /** La purge quotidienne a constaté l'échéance de la connexion (McpConnection::isExpiredAt()). */
    case Expired = 'expired';

    /** La personne a révoqué la connexion depuis « Mes connexions MCP » (#650). */
    case Person = 'person';

    /** Le mot de passe du compte a changé : toutes ses connexions sont coupées (#650). */
    case PasswordChanged = 'password-changed';

    /** Le compte a perdu le palier de confiance : toutes ses connexions sont coupées (#650). */
    case TrustedTierWithdrawn = 'trusted-tier-withdrawn';
}
