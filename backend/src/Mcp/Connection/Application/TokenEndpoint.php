<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Exception\CompromisedMcpConnectionException;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientIdException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
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
 * 4. **Une connexion compromise est révoquée après la transaction** (#649).
 *    league juge le rafraîchissement dans sa validation, par
 *    `RefreshTokenRepository::isRefreshTokenRevoked()`, **sous le verrou de
 *    la ligne de la connexion** (`FOR UPDATE`, tenu jusqu'au COMMIT ou au
 *    ROLLBACK) : deux rafraîchissements simultanés d'un même jeton — la
 *    course de league, chacun lisant le jeton non révoqué avant que l'autre
 *    l'ait tourné — se suivent, le second relit la rotation du premier. Un
 *    jeton déjà tourné qui revient sur une connexion active est une
 *    réutilisation (OAuth 2.1 §4.3.1) : le dépôt lève
 *    CompromisedMcpConnectionException, la transaction est annulée (rien de
 *    l'échange ne survit, l'EntityManager est fermé), et c'est **ici, hors de
 *    la transaction**, que la connexion est révoquée avec sa cause par
 *    McpConnectionRevokerInterface — une instruction SQL, valable
 *    EntityManager fermé, puis la ligne d'audit `mcp-connection-revoked`. Le
 *    client reçoit le refus ordinaire de league, mot pour mot : la détection
 *    ne lui apprend rien. Un client qui rejoue un rafraîchissement après une
 *    réponse perdue y perd sa connexion — le prix de la rotation, accepté
 *    (`RefreshRotationTest`). Un code déjà échangé qui revient — l'un après
 *    l'autre comme en concurrence — est jugé de même, sous le même verrou,
 *    par `AuthCodeRepository::isAuthCodeRevoked()` (OAuth 2.1 §4.1.2) : la
 *    connexion est révoquée, cause `code-replay`, et les jetons que le code
 *    avait émis meurent avec elle (`CodeReplayTest`).
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
        private McpConnectionRevokerInterface $revoker,
    ) {
    }

    public function respond(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $request = FormEncodedRequest::parse($request, self::READ_PARAMETERS);
        $this->refuseForeignResource($request);

        try {
            return $this->transaction->run(fn(): ResponseInterface => $this->server->respondToAccessTokenRequest($request, $response));
        } catch (CompromisedMcpConnectionException $compromise) {
            // La transaction est déjà annulée : la révocation s'écrit hors
            // d'elle, et survit au ROLLBACK de l'échange.
            $this->revoker->revoke($compromise->connectionId(), $compromise->revocationCause());

            throw $this->refusalOf($compromise->revocationCause());
        }
    }

    /**
     * Le refus que league aurait rendu sans la révocation, mot pour mot
     * (`OAuthServerException`, RFC 6749 §5.2) : un attaquant n'apprend rien
     * de plus qu'avant. Un jeton de rafraîchissement réutilisé est le refus
     * d'un jeton révoqué ; un code rejoué, celui d'un code révoqué. Les
     * autres causes ne naissent pas au point de jeton (le client
     * au point de révocation, un nouvel accord au consentement, l'échéance à
     * la purge) : le refus générique d'un grant invalide, fermé.
     */
    private function refusalOf(RevocationCause $cause): OAuthServerException
    {
        return match ($cause) {
            RevocationCause::RefreshTokenReuse => OAuthServerException::invalidRefreshToken('Token has been revoked'),
            RevocationCause::CodeReplay => OAuthServerException::invalidGrant('Authorization code has been revoked'),
            RevocationCause::Client, RevocationCause::Superseded, RevocationCause::Expired => OAuthServerException::invalidGrant(),
        };
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
