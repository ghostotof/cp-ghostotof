<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

/**
 * La cause pour laquelle le point de jeton refuse un code d'autorisation ou
 * un jeton de rafraîchissement **authentique** — que le serveur a émis
 * (#725). Le client reçoit le refus de league, inchangé (RFC 6749 §5.2) : la
 * cause ne sert qu'à nommer le refus au journal de sécurité, jamais à le
 * décrire au client.
 *
 * Classée d'après l'indication de league
 * ({@see \App\Mcp\Connection\Infrastructure\League\LeagueGrantRefusals}) ;
 * une compromission (réutilisation d'un jeton remplacé, rejeu d'un code) n'en
 * est pas une : elle révoque la connexion, cause RevocationCause.
 *
 * La valeur est un mot stable (kebab), sans rien de l'artefact : elle peut
 * entrer dans une ligne de journal telle quelle.
 */
enum GrantRefusal: string
{
    /** Code sans `code_verifier` (PKCE, RFC 7636 §4.5). */
    case VerifierMissing = 'verifier-missing';

    /** `code_verifier` hors de la forme de RFC 7636 §4.1. */
    case VerifierMalformed = 'verifier-malformed';

    /**
     * `code_verifier` bien formé mais faux, sur un code vivant : le signe d'un
     * code intercepté sans son vérificateur (RFC 7636 §1).
     */
    case VerifierMismatch = 'verifier-mismatch';

    /** Code présenté après son échéance (dix minutes, jugée par league sur sa charge). */
    case CodeExpired = 'code-expired';

    /**
     * Code refusé sous le verrou de sa connexion : sa ligne n'est plus en base
     * (purgée), ou sa connexion est révoquée — jamais un rejeu, qui est une
     * compromission.
     */
    case CodeRefused = 'code-refused';

    /** Artefact authentique présenté comme code, qui n'en est pas un (un jeton de rafraîchissement). */
    case NotACode = 'not-a-code';

    /** `redirect_uri` absente ou autre que celle de l'autorisation (RFC 6749 §4.1.3). */
    case RedirectMismatch = 'redirect-mismatch';

    /** Jeton de rafraîchissement présenté après son échéance (jugée par league sur sa charge). */
    case TokenExpired = 'token-expired';

    /**
     * Jeton de rafraîchissement refusé sous le verrou de sa connexion : sa
     * ligne n'est plus en base, son échéance passée à l'horloge du serveur,
     * sa connexion révoquée ou expirée, son compte hors palier de confiance —
     * jamais une réutilisation, qui est une compromission.
     */
    case TokenRefused = 'token-refused';

    /** Artefact authentique présenté comme jeton de rafraîchissement, qui n'en est pas un (un code). */
    case NotARefreshToken = 'not-a-refresh-token';

    /**
     * Scope refusé : demandé au rafraîchissement, inconnu ou absent de la
     * connexion ; ou, à l'échange d'un code, un scope de sa charge devenu
     * inconnu depuis l'autorisation.
     */
    case InvalidScope = 'invalid-scope';

    /** Code ou jeton présenté par un autre client enregistré que le sien. */
    case ForeignClient = 'foreign-client';

    /** `resource` autre que le serveur MCP du site (RFC 8707, `invalid_target`). */
    case ForeignResource = 'foreign-resource';

    /**
     * Tout autre refus de league : aucun refus d'un artefact authentique ne
     * se perd. Un libellé de league changé à sa montée de version y tombe, et
     * le test de sa cause rougit.
     */
    case Unrecognised = 'unrecognised';
}
