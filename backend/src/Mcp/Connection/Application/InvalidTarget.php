<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Le refus `invalid_target` (RFC 8707 §2) : la ressource demandée n'est pas
 * celle du serveur MCP du site (#647). Écrit une fois pour ses deux sites, le
 * point d'autorisation (renvoyé au client sur la redirection validée) et le
 * point de jeton (le JSON de league, sans redirection).
 *
 * league n'a pas de fabrique pour cette erreur : elle n'existe que dans
 * RFC 8707, que league ne connaît pas.
 */
final class InvalidTarget
{
    /**
     * Le code de l'exception de league, qui ne sert qu'à elle : celui de
     * `invalid_request`, faute d'un code propre à RFC 8707. Jamais rendu au
     * client, qui lit `error`.
     */
    private const int CODE = 3;

    private function __construct()
    {
    }

    /**
     * @param string|null $redirectUri la redirection **validée** vers laquelle
     *                                 renvoyer l'erreur, `state` déjà joint ;
     *                                 `null` pour la réponse JSON (400)
     */
    public static function refusal(?string $redirectUri = null): OAuthServerException
    {
        return new OAuthServerException(
            'The requested resource is invalid, missing, unknown, or malformed.',
            self::CODE,
            'invalid_target',
            400,
            'Check the `resource` parameter: it must be the URL of this site\'s MCP server.',
            $redirectUri,
        );
    }
}
