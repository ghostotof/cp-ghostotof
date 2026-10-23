<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le point de jeton (#647) : deux contrôles avant league, puis league.
 *
 * 1. **Aucun paramètre lu n'est répété** (RFC 6749 §3.2) : PHP garde la
 *    dernière occurrence d'un corps `application/x-www-form-urlencoded`, un
 *    autre analyseur la première (QueryParameterPollution, la règle du point
 *    d'autorisation). Un refus `invalid_request`.
 * 2. **La ressource** (RFC 8707 §2.2) : facultative ici, mais présente elle
 *    doit être le serveur MCP du site, sinon `invalid_target` — sans
 *    redirection, le point de jeton n'en fait jamais. Un jeton d'accès porte
 *    toujours cette audience-là (AccessTokenRepository), qu'elle soit demandée
 *    ou non.
 * 3. **league** : client, code (PKCE `S256` compris, la redirection de
 *    l'autorisation exigée à l'identique) ou rafraîchissement, émission.
 *
 * Non traité ici, connu : les courses de league — les jetons sont émis avant
 * la révocation du code, et un rafraîchissement est révoqué après avoir été
 * vérifié — sont l'objet de #649 (rotation, réutilisation). Un code échangé
 * deux fois **l'une après l'autre** est refusé la seconde fois.
 */
final readonly class TokenEndpoint implements TokenEndpointInterface
{
    /**
     * Les paramètres que league ou ce point lisent, pour l'un ou l'autre
     * grant. `client_secret` n'y est pas : aucun client n'en a (clients
     * publics seulement), league ne le lit pas pour eux.
     */
    public const array READ_PARAMETERS = ['grant_type', 'code', 'redirect_uri', 'client_id', 'code_verifier', 'refresh_token', 'scope', 'resource'];

    public function __construct(
        private AuthorizationServer $server,
        private OAuthEndpoints $endpoints,
    ) {
    }

    public function respond(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->refuseRepeatedParameters($request);
        $this->refuseForeignResource($request);

        return $this->server->respondToAccessTokenRequest($request, $response);
    }

    /**
     * Lu sur le corps brut, que le pont PSR-7 recopie : les paramètres analysés
     * n'en gardent déjà plus qu'une occurrence.
     *
     * @throws OAuthServerException `invalid_request`
     */
    private function refuseRepeatedParameters(ServerRequestInterface $request): void
    {
        $polluted = QueryParameterPollution::firstPollutedName((string) $request->getBody(), self::READ_PARAMETERS);

        if (null !== $polluted) {
            throw OAuthServerException::invalidRequest($polluted, \sprintf('Check the `%s` parameter: it must be given once, under its exact name.', $polluted));
        }
    }

    /**
     * Une ressource reçue autrement qu'en chaîne (`resource[]=…`) n'est pas
     * celle du site non plus.
     *
     * @throws OAuthServerException `invalid_target`
     */
    private function refuseForeignResource(ServerRequestInterface $request): void
    {
        $body = $request->getParsedBody();

        if (!\is_array($body) || !\array_key_exists('resource', $body)) {
            return;
        }

        if ($this->endpoints->resource() !== $body['resource']) {
            throw InvalidTarget::refusal();
        }
    }
}
