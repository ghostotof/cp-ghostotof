<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\Uri\Uri;
use Psr\Http\Message\ServerRequestInterface;

/**
 * La demande d'autorisation de la connexion MCP (#647), jugée en trois temps,
 * dans un ordre qui décide de la forme du refus :
 *
 * 1. **Avant league, sans redirection** — rien n'est encore validé, un refus
 *    ne peut donc partir que vers l'appelant (400 JSON) :
 *    - un paramètre attendu reçu autrement qu'en chaîne (`client_id[]=x`) :
 *      league le passerait tel quel à une signature `string`, un TypeError,
 *      un 500 à la main de quiconque ;
 *    - `redirect_uri` absente : league retomberait sur l'unique redirection
 *      enregistrée, sans le port qu'un client natif a ouvert (RFC 8252 §7.3),
 *      et le code partirait où personne n'écoute ;
 *    - `state` au-delà de STATE_MAX_LENGTH : il serait recopié dans chaque
 *      redirection et dans l'URL de la page de consentement ; jamais renvoyé.
 * 2. **league** : client connu, redirection enregistrée (sinon
 *    `invalid_client`, sans redirection), PKCE `S256` (le grant, seul à en
 *    décider), scope (`invalid_scope`, redirigé).
 * 3. **Après league, avec redirection** — la ressource (RFC 8707) : exigée et
 *    égale au serveur MCP du site, sinon `invalid_target`, renvoyé au client
 *    sur la redirection que league vient de valider, `state` compris.
 */
final readonly class AuthorizationRequestValidator implements AuthorizationRequestValidatorInterface
{
    /**
     * La borne de `state`. RFC 6749 (annexe A.5) le veut en VSCHAR, un octet
     * par caractère : la borne compte des octets. 1024 laisse sa place à un
     * `state` qui porte un JWT ou un état chiffré, comme certains clients le
     * font, et tient sous la limite de ligne de requête de nginx.
     */
    public const int STATE_MAX_LENGTH = 1024;

    /** Les paramètres que league ou ce validateur lisent : chacun doit être une chaîne. */
    private const array EXPECTED_PARAMETERS = ['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource'];

    /**
     * Le code de l'exception de league, qui ne sert qu'à elle : celui de
     * `invalid_request`, faute d'un code propre à RFC 8707. Jamais rendu au
     * client, qui lit `error`.
     */
    private const int INVALID_TARGET_CODE = 3;

    public function __construct(
        private AuthorizationServer $server,
        private OAuthEndpoints $endpoints,
    ) {
    }

    public function validate(ServerRequestInterface $request): AuthorizationRequestSummary
    {
        $parameters = $this->wellFormedParameters($request);
        $redirectUri = $parameters['redirect_uri']
            ?? throw OAuthServerException::invalidRequest('redirect_uri', 'Check the `redirect_uri` parameter: it is required.');
        // league valide cette redirection-là, telle que reçue, et la garde
        // dans la demande (setRedirectUri()).
        $authorization = $this->server->validateAuthorizationRequest($request);
        $resource = $parameters['resource'] ?? null;

        if ($this->endpoints->resource() !== $resource) {
            throw $this->invalidTarget($redirectUri, $authorization->getState());
        }

        return new AuthorizationRequestSummary(
            clientId: $authorization->getClient()->getIdentifier(),
            clientName: $authorization->getClient()->getName(),
            redirectUri: $redirectUri,
            redirectHost: Uri::parse($redirectUri)?->getHost() ?? throw ValidatedRedirectWithoutHostException::create(),
            scope: $this->scopeOf($authorization),
            resource: $resource,
            authorizationRequest: $authorization,
        );
    }

    /**
     * Les paramètres attendus présents, chacun une chaîne, `state` borné.
     * Aucun refus d'ici ne redirige, et aucun ne recopie la valeur reçue (le
     * message et l'indication de league ne nomment que le paramètre).
     *
     * @return array<string, string>
     *
     * @throws OAuthServerException `invalid_request`, sans redirection
     */
    private function wellFormedParameters(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $parameters = [];

        foreach (self::EXPECTED_PARAMETERS as $name) {
            if (!\array_key_exists($name, $query)) {
                continue;
            }

            $value = $query[$name];

            if (!\is_string($value)) {
                throw OAuthServerException::invalidRequest($name, \sprintf('Check the `%s` parameter: it must be a single string.', $name));
            }

            $parameters[$name] = $value;
        }

        if (isset($parameters['state']) && \strlen($parameters['state']) > self::STATE_MAX_LENGTH) {
            throw OAuthServerException::invalidRequest('state', \sprintf('Check the `state` parameter: at most %d characters.', self::STATE_MAX_LENGTH));
        }

        return $parameters;
    }

    /**
     * `invalid_target` (RFC 8707 §2), vers la redirection validée, `state`
     * repris comme league le reprend pour un scope invalide
     * (`makeRedirectUri()` : `?` ou `&` selon que la redirection a déjà une
     * requête).
     */
    private function invalidTarget(string $redirectUri, ?string $state): OAuthServerException
    {
        if (null !== $state) {
            $redirectUri .= (str_contains($redirectUri, '?') ? '&' : '?').http_build_query(['state' => $state]);
        }

        return new OAuthServerException(
            'The requested resource is invalid, missing, unknown, or malformed.',
            self::INVALID_TARGET_CODE,
            'invalid_target',
            400,
            'Check the `resource` parameter: it must be the URL of this site\'s MCP server.',
            $redirectUri,
        );
    }

    private function scopeOf(AuthorizationRequestInterface $authorization): string
    {
        $identifiers = [];

        foreach ($authorization->getScopes() as $scope) {
            $identifiers[] = $scope->getIdentifier();
        }

        return implode(' ', $identifiers);
    }
}
