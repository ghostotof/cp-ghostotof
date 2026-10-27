<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

/**
 * Le profil OAuth que le serveur sert (#647, #726) : les deux grants et la
 * seule méthode PKCE, **écrits une fois**, ici.
 *
 * Une classe d'Application parce que ses lecteurs sont des deux couches :
 * les métadonnées (OAuthServerMetadata, Application, qui n'importe jamais
 * Infrastructure) annoncent ce que le grant, le client et le lecteur de
 * charge d'Infrastructure\League appliquent — `LoopbackAwareAuthCodeGrant`
 * (la méthode exigée à l'autorisation), `OAuthClient` et `ClientRepository`
 * (les grants servis), `GrantPayloadReader` (la méthode d'un code),
 * `LeagueCodeExchangeSupersession` et `LeagueGrantRefusalAudit` (le grant
 * d'une requête). Une annonce et une règle lues à la même constante ne
 * divergent pas : un client tente tout ce qu'un document lui annonce.
 *
 * league n'a pas de constante pour ses grants — c'est le retour de
 * `AuthCodeGrant::getIdentifier()`, une méthode d'instance — ni pour sa
 * méthode `S256` (son vérificateur la rend par `getMethod()`).
 */
final class OAuthProfile
{
    /** Le grant du code d'autorisation (RFC 6749 §4.1.3). */
    public const string AUTHORIZATION_CODE_GRANT = 'authorization_code';

    /** Le grant du rafraîchissement (RFC 6749 §6). */
    public const string REFRESH_TOKEN_GRANT = 'refresh_token';

    /**
     * Les deux grants servis, dans l'ordre que les métadonnées annoncent
     * (`grant_types_supported`).
     */
    public const array SERVED_GRANTS = [self::AUTHORIZATION_CODE_GRANT, self::REFRESH_TOKEN_GRANT];

    /**
     * La seule méthode PKCE admise (RFC 7636 §4.3, la casse compte) : `plain`
     * est refusé, une méthode absente aussi.
     */
    public const string CODE_CHALLENGE_METHOD = 'S256';

    private function __construct()
    {
    }
}
