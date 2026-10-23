<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le grant du code d'autorisation de league, dont seule la comparaison de la
 * redirection change (#647).
 *
 * Le validateur de league ({@see \League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator})
 * ne tient pour boucle locale que `127.0.0.1` et `[::1]`, jamais `localhost` :
 * `http://localhost/callback` enregistrée refusait `http://localhost:49152/callback`,
 * la forme qu'ouvrent les clients natifs (RFC 8252 §7.3). La règle du dépôt
 * est écrite une fois, dans McpClient::allowsRedirect() : ce grant y
 * délègue, sans la réécrire.
 *
 * Le refus garde le comportement de league : l'événement
 * `CLIENT_AUTHENTICATION_FAILED`, puis `invalid_client`, sans redirection —
 * jamais vers une URI que rien n'a validée.
 */
final class LoopbackAwareAuthCodeGrant extends AuthCodeGrant
{
    /**
     * @throws OAuthServerException `invalid_client` quand la redirection n'est pas enregistrée
     */
    protected function validateRedirectUri(string $redirectUri, ClientEntityInterface $client, ServerRequestInterface $request): void
    {
        // Un client que ClientRepository n'a pas rendu ne porte pas de
        // McpClient : refusé comme une redirection inconnue.
        if ($client instanceof OAuthClient && $client->mcpClient()->allowsRedirect($redirectUri)) {
            return;
        }

        $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));

        throw OAuthServerException::invalidClient($request);
    }
}
