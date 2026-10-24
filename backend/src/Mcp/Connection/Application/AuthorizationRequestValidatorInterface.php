<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Valide une demande d'autorisation OAuth de la connexion MCP (#647) : le
 * point d'autorisation (`GET /api/oauth/authorize`) et, à la tâche 6, le
 * consentement, qui revalide la même demande avant d'émettre un code.
 *
 * Interface parce que les contrôleurs qui la servent dépendent d'elle et non
 * de sa mise en œuvre (règle du dépôt : contrôleur → interface → service).
 */
interface AuthorizationRequestValidatorInterface
{
    /**
     * Le refus est une OAuthServerException de league, que l'appelant rend
     * par generateHttpResponse() : avec redirection (`hasRedirect()`) une fois
     * le client et sa redirection validés, sans redirection avant.
     *
     * @throws OAuthServerException
     */
    public function validate(ServerRequestInterface $request): AuthorizationRequestSummary;
}
