<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le point de jeton OAuth de la connexion MCP (#647), `POST /api/oauth/token` :
 * l'échange d'un code d'autorisation, ou d'un jeton de rafraîchissement,
 * contre un jeton d'accès et un nouveau jeton de rafraîchissement.
 *
 * Interface parce que le contrôleur qui le sert dépend d'elle et non de sa
 * mise en œuvre (règle du dépôt : contrôleur → interface → service).
 */
interface TokenEndpointInterface
{
    /**
     * La réponse de league (RFC 6749 §5.1), écrite dans `$response`.
     *
     * Le refus est une OAuthServerException de league, que l'appelant rend
     * par generateHttpResponse() : le JSON de RFC 6749 §5.2, jamais une
     * redirection.
     *
     * @throws OAuthServerException
     */
    public function respond(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;
}
