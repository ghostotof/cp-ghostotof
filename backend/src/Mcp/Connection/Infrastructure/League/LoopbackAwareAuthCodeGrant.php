<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le grant du code d'autorisation de league, dont deux décisions changent
 * (#647) : la comparaison de la redirection, et PKCE réduit à `S256`.
 *
 * **Redirection.** Le validateur de league ({@see \League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator})
 * ne tient pour boucle locale que `127.0.0.1` et `[::1]`, jamais `localhost` :
 * `http://localhost/callback` enregistrée refusait `http://localhost:49152/callback`,
 * la forme qu'ouvrent les clients natifs (RFC 8252 §7.3). La règle du dépôt
 * est écrite une fois, dans McpClient::allowsRedirect() : ce grant y
 * délègue, sans la réécrire. Le refus garde le comportement de league :
 * l'événement `CLIENT_AUTHENTICATION_FAILED`, puis `invalid_client`, sans
 * redirection — jamais vers une URI que rien n'a validée.
 *
 * **PKCE.** league accepte `plain`, et lit une méthode absente comme `plain`
 * (ses vérificateurs sont privés, `plain` toujours enregistré). Ce grant est
 * le seul endroit qui en décide, là où league décide : une demande sans
 * `code_challenge`, ou dont `code_challenge_method` n'est pas exactement
 * `S256`, est un `invalid_request` (OAuth 2.1 §4.1.1, profil MCP). L'erreur
 * est redirigée vers le client, `state` compris, comme league le fait pour
 * un scope invalide — mais seulement après avoir validé le client et sa
 * redirection, dans l'ordre de league : un client ou une redirection
 * inconnus restent un `invalid_client` sans redirection.
 */
final class LoopbackAwareAuthCodeGrant extends AuthCodeGrant
{
    /** La seule méthode PKCE admise. */
    public const string CODE_CHALLENGE_METHOD = 'S256';

    /**
     * @throws OAuthServerException `invalid_request` redirigé quand PKCE n'est pas `S256` ; les refus de league sinon
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        $refusal = $this->pkceRefusal($request);

        if (null === $refusal) {
            return parent::validateAuthorizationRequest($request);
        }

        throw new OAuthServerException(
            'The request is missing a required parameter, includes an invalid parameter value, includes a parameter more than once, or is otherwise malformed.',
            3,
            'invalid_request',
            400,
            $refusal,
            $this->validatedErrorRedirectUri($request),
        );
    }

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

    /**
     * L'indication (`hint`) du refus PKCE, ou `null` si la demande porte un
     * défi et la méthode `S256` exactement (la casse compte, RFC 7636 §4.3).
     * La forme du défi reste jugée par league.
     */
    private function pkceRefusal(ServerRequestInterface $request): ?string
    {
        if (null === $this->getQueryStringParameter('code_challenge', $request)) {
            return 'Check the `code_challenge` parameter: PKCE with the `S256` method is required.';
        }

        if (self::CODE_CHALLENGE_METHOD !== $this->getQueryStringParameter('code_challenge_method', $request)) {
            return 'Check the `code_challenge_method` parameter: only `S256` is supported.';
        }

        return null;
    }

    /**
     * L'URI vers laquelle renvoyer l'erreur, établie comme league l'établit en
     * tête de validateAuthorizationRequest() : client connu et servi par ce
     * grant, redirection demandée validée (ou l'unique enregistrée), `state`
     * repris. Chaque étape lève le refus de league, sans redirection. Une
     * copie de ces premières lignes plutôt qu'un appel du parent sur une
     * requête retouchée : le parent valide aussi PKCE, et rien ne doit
     * rediriger vers une URI qu'il n'a pas validée.
     *
     * @throws OAuthServerException `invalid_request` (client_id), `invalid_client`, `unauthorized_client`
     */
    private function validatedErrorRedirectUri(ServerRequestInterface $request): string
    {
        $clientId = $this->getQueryStringParameter('client_id', $request, $this->getServerParameter('PHP_AUTH_USER', $request))
            ?? throw OAuthServerException::invalidRequest('client_id');
        $client = $this->getClientEntityOrFail($clientId, $request);
        $redirectUri = $this->getQueryStringParameter('redirect_uri', $request);

        if (null !== $redirectUri) {
            $this->validateRedirectUri($redirectUri, $client, $request);
        } elseif (!\is_array($client->getRedirectUri()) || 1 !== \count($client->getRedirectUri())) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidClient($request);
        }

        $state = $this->getQueryStringParameter('state', $request);

        return $this->makeRedirectUri(
            $redirectUri ?? $this->getClientRedirectUri($client),
            null !== $state ? ['state' => $state] : [],
        );
    }
}
