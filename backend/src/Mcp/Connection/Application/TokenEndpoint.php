<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Exception\InvalidMcpClientIdException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le point de jeton (#647) : deux contrôles avant league, puis league, dans
 * une transaction.
 *
 * 1. **Un formulaire, aucun paramètre lu répété** (RFC 6749 §3.2,
 *    FormEncodedRequest) : un autre type de contenu, ou un paramètre que PHP
 *    et un autre analyseur liraient différemment (QueryParameterPollution, la
 *    règle du point d'autorisation), est un `invalid_request` ; league lit les
 *    paramètres tirés du corps contrôlé.
 * 2. **La ressource** (RFC 8707 §2.2) : facultative ici, mais présente elle
 *    doit être le serveur MCP du site, sinon `invalid_target` — sans
 *    redirection, le point de jeton n'en fait jamais. Un jeton d'accès porte
 *    toujours cette audience-là (AccessTokenRepository), qu'elle soit demandée
 *    ou non. **Le client est authentifié avant** que la ressource soit jugée,
 *    comme à la révocation : un client inconnu reçoit `invalid_client`, jamais
 *    un `invalid_target` qui jugerait la demande de quelqu'un que le serveur
 *    ne connaît pas. Un client public se désigne par `client_id` dans le
 *    corps (la méthode `none` que les métadonnées annoncent) ; un client qui
 *    ne se désignerait qu'en HTTP Basic, ressource étrangère comprise, reçoit
 *    `invalid_client` aussi — deux refus, aucun jeton.
 * 3. **league** : client, code (PKCE `S256` compris, la redirection de
 *    l'autorisation exigée à l'identique) ou rafraîchissement, émission —
 *    **dans une seule transaction** (TransactionInterface). league écrit en
 *    plusieurs fois : à l'échange, le jeton d'accès, le rafraîchissement,
 *    puis la révocation du code ; au rafraîchissement, la révocation de
 *    l'ancien jeton d'accès et de l'ancien rafraîchissement **avant**
 *    l'émission des nouveaux. Une panne au milieu annule tout : jamais un
 *    jeton d'accès orphelin avec un code encore échangeable, jamais un client
 *    privé de son ancien rafraîchissement sans en avoir reçu un nouveau
 *    (`TokenControllerTest`, panne forcée par un déclencheur).
 *
 * Non traité ici, connu : les courses de league — deux échanges simultanés
 * d'un même code, ou deux rafraîchissements simultanés, lisent chacun le
 * code ou le jeton non révoqué avant que l'autre l'ait révoqué — sont l'objet
 * de #649 (rotation, réutilisation) ; la transaction ne les aggrave pas
 * (chaque requête voit ce que l'autre a validé, `READ COMMITTED`) et ne les
 * ferme pas non plus. Un code échangé deux fois **l'une après l'autre** est
 * refusé la seconde fois.
 */
final readonly class TokenEndpoint implements TokenEndpointInterface
{
    /**
     * Les paramètres que league ou ce point lisent, pour l'un ou l'autre
     * grant. `client_secret` n'y est pas : aucun client n'en a (clients
     * publics seulement). league le lit, mais ne le confronte à rien pour un
     * client public (`AbstractGrant::validateClient()` ne consulte le dépôt
     * que pour un client confidentiel) : un secret superflu est ignoré, pas
     * refusé, et sa répétition n'est pas surveillée.
     */
    private const array READ_PARAMETERS = ['grant_type', 'code', 'redirect_uri', 'client_id', 'code_verifier', 'refresh_token', 'scope', 'resource'];

    public function __construct(
        private AuthorizationServer $server,
        private OAuthEndpoints $endpoints,
        private McpClientRepositoryInterface $clients,
        private TransactionInterface $transaction,
    ) {
    }

    public function respond(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $request = FormEncodedRequest::parse($request, self::READ_PARAMETERS);
        $this->refuseForeignResource($request);

        return $this->transaction->run(fn(): ResponseInterface => $this->server->respondToAccessTokenRequest($request, $response));
    }

    /**
     * Une ressource reçue autrement qu'en chaîne (`resource[]=…`) n'est pas
     * celle du site non plus. Le client est authentifié d'abord ; une
     * ressource absente ou celle du site laisse league l'authentifier
     * lui-même, sans requête de plus ici.
     *
     * @throws OAuthServerException `invalid_client`, puis `invalid_target`
     */
    private function refuseForeignResource(ServerRequestInterface $request): void
    {
        $body = $request->getParsedBody();

        if (!\is_array($body) || !\array_key_exists('resource', $body) || $this->endpoints->resource() === $body['resource']) {
            return;
        }

        if (!$this->isKnownClient($body['client_id'] ?? null)) {
            throw OAuthServerException::invalidClient($request);
        }

        throw InvalidTarget::refusal();
    }

    /**
     * Un identifiant hors de la forme du domaine (McpClientId) ne désigne
     * aucun client : il n'est pas cherché (un octet nul ou un UTF-8 invalide
     * n'atteignent pas PostgreSQL).
     */
    private function isKnownClient(mixed $clientId): bool
    {
        if (!\is_string($clientId)) {
            return false;
        }

        try {
            return null !== $this->clients->findOneByClientId((string) McpClientId::fromString($clientId));
        } catch (InvalidMcpClientIdException) {
            return false;
        }
    }
}
