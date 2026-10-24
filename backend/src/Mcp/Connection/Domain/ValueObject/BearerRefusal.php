<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

/**
 * La cause pour laquelle un jeton présenté en `Authorization: Bearer` sur
 * `/api/mcp` n'ouvre rien (#648). Quelle qu'elle soit, le client reçoit la
 * même réponse, un 401 `error="invalid_token"` (RFC 6750 §3.1) : la cause ne
 * sert qu'à nommer le refus côté serveur, jamais à le décrire au client.
 *
 * Les cinq premières se lisent sur le jeton et sa connexion, dans cet ordre
 * ({@see \App\Mcp\Connection\Domain\Entity\McpAccessToken::refusalAt()}) ;
 * `AccountNotTrusted`, `Malformed` et `Unknown` sont posées par
 * l'authentificateur du firewall `mcp`, qui voit ce que le domaine du jeton
 * ne voit pas : le palier du compte, la forme de la chaîne reçue, l'absence
 * de ligne.
 *
 * La valeur est un mot stable (kebab), sans rien du jeton : elle peut entrer
 * dans un message d'exception ou une ligne de journal telle quelle.
 */
enum BearerRefusal: string
{
    /** Le jeton a été révoqué (RFC 7009, ou avec son jeton de rafraîchissement). */
    case TokenRevoked = 'token-revoked';

    /** L'échéance du jeton est atteinte (`expiresAt <= now`). */
    case TokenExpired = 'token-expired';

    /** Le jeton a été émis pour une autre ressource que `/api/mcp` (RFC 8707), comparée octet pour octet. */
    case ForeignAudience = 'foreign-audience';

    /** La connexion MCP du jeton a été révoquée. */
    case ConnectionRevoked = 'connection-revoked';

    /** La connexion MCP du jeton a expiré : trop longtemps sans usage, ou trop longtemps après l'octroi. */
    case ConnectionExpired = 'connection-expired';

    /** Le compte de la connexion n'atteint plus `ROLE_TRUSTED` (posé par l'authentificateur). */
    case AccountNotTrusted = 'account-not-trusted';

    /** La chaîne reçue n'a pas la forme d'un jeton émis, jugée avant toute requête (posé par l'authentificateur). */
    case Malformed = 'malformed';

    /** Aucun jeton n'a cette empreinte (posé par l'authentificateur). */
    case Unknown = 'unknown';
}
