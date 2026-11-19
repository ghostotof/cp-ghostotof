<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\Cimd\CimdClientAdmissionInterface;
use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Application\OAuthProfile;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use DateInterval;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
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
    /**
     * L'attribut que validateAuthorizationRequest() pose sur la requête qu'il
     * donne à league : « ce client se juge au point d'autorisation ». Un
     * attribut de la requête, pas un état du grant (un service partagé).
     * Il est posé par ce grant lui-même, sans condition, dans
     * validateAuthorizationRequest() : jamais lu d'une requête du client (un
     * paramètre de requête n'est pas un attribut). Le pont PSR-7 recopie bien
     * tous les attributs de la requête Symfony (valeurs par défaut de la route,
     * valeurs posées par un écouteur) ; un attribut de ce nom posé là, au point
     * de jeton, ne ferait que refuser davantage une ligne `cimd`, jamais
     * contourner le garde : seule son absence sert la ligne, et le grant le pose
     * avant toute lecture du client au point d'autorisation.
     */
    private const string AUTHORIZATION_POINT_ATTRIBUTE = 'mcp.authorization_point';

    /**
     * L'indication du refus d'un code dont la redirection n'est plus admise
     * par son client (#652), constante : aucune URL, aucun identifiant.
     * Écrite ici seule, LeagueGrantRefusals la lit pour classer le refus.
     */
    public const string REDIRECT_NO_LONGER_REGISTERED_HINT = 'The redirect URI of the authorization code is no longer registered for this client.';

    /** Le message d'une panne de l'audit du refus d'une ligne `cimd` non admise, constant (#651). */
    private const string STALE_ROW_REFUSAL_NOT_AUDITED = 'Refused stored CIMD client row not audited.';

    /**
     * L'audit est injecté avec la ligne d'audit tolérante à sa panne
     * ({@see FailSafeAudit}, #725) : le refus de league ne dépend jamais de lui.
     */
    public function __construct(
        AuthCodeRepositoryInterface $authCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $authCodeTTL,
        private readonly SecurityAuditLoggerInterface $audit,
        private readonly FailSafeAudit $failSafeAudit,
    ) {
        parent::__construct($authCodeRepository, $refreshTokenRepository, $authCodeTTL);
    }

    /**
     * Refuse, à l'échange, un code dont la redirection n'est plus admise par
     * son client (#652) : la révision des redirections ne coupe aucune
     * connexion ouverte, elle ne doit pas non plus laisser aboutir un code déjà
     * accordé pour une redirection retirée. Le client est relu en base ici,
     * et la règle est celle de l'accord (`McpClient::allowsRedirect()`, tolérance
     * loopback comprise), jamais une égalité de chaînes.
     *
     * **League juge d'abord** : l'échéance, puis le rejeu, sont ceux de son
     * `validateAuthorizationCode()`. Le refus n'est donc levé ici que pour un
     * code **non échu** (`time()`, l'horloge de league, la charge chiffrée
     * étant la sienne) et **non consommé** (`isAuthCodeRevoked()`, qui lève
     * elle-même `AuthorizationCodeReplayedException` sur un code déjà échangé,
     * laissée remonter : le point de jeton révoque ou audite le rejeu comme
     * avant). Sinon la main revient au parent, qui refuse sous sa propre cause.
     * Le second appel de `isAuthCodeRevoked()` par league, dans la même
     * transaction, relit sous le même verrou de ligne : aucun effet de bord.
     *
     * Le contrôle ne s'exerce que sur un code lisible, émis à ce client et lié
     * à une redirection : tout autre cas (illisible, autre client, redirection
     * absente) reste jugé par league, sous sa propre cause.
     *
     * @throws OAuthServerException `invalid_grant` ; les refus de league sinon
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $client = $this->validateClient($request);
        $payload = $this->payloadOfTheCode($request, $client);

        if (null !== $payload
            && \is_string($payload->redirect_uri ?? null)
            && (!$client instanceof OAuthClient || !$client->mcpClient()->allowsRedirect($payload->redirect_uri))
            && \is_string($payload->auth_code_id ?? null)
            && \is_int($payload->expire_time ?? null)
            && time() <= $payload->expire_time
            && !$this->authCodeRepository->isAuthCodeRevoked($payload->auth_code_id)) {
            throw OAuthServerException::invalidGrant(self::REDIRECT_NO_LONGER_REGISTERED_HINT);
        }

        return parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
    }

    /**
     * La charge du code présenté, ou `null` quand le code est illisible ou émis
     * à un autre client : league en juge.
     */
    private function payloadOfTheCode(ServerRequestInterface $request, ClientEntityInterface $client): ?object
    {
        $encrypted = $this->getRequestParameter('code', $request);

        if (null === $encrypted) {
            return null;
        }

        try {
            $payload = json_decode($this->decrypt($encrypted));
        } catch (LogicException) {
            return null;
        }

        if (!\is_object($payload) || ($payload->client_id ?? null) !== $client->getIdentifier()) {
            return null;
        }

        return $payload;
    }

    /**
     * @throws OAuthServerException `invalid_request` redirigé quand PKCE n'est pas `S256` ; les refus de league sinon
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        // À partir d'ici, tout client lu par league l'est au point
        // d'autorisation : getClientEntityOrFail() y exige l'admission d'un
        // client `cimd`.
        $request = $request->withAttribute(self::AUTHORIZATION_POINT_ATTRIBUTE, true);
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
     * Le client que league juge : celui que l'admission a déposé dans la requête
     * quand le `client_id` est l'URL d'un document que le client publie (CIMD,
     * #651), sinon la ligne que le dépôt de clients rend.
     *
     * **Pourquoi le test de type suffit contre un attribut contrefait.**
     * L'attribut n'est lu que s'il est un {@see McpClient} dont l'identifiant
     * est celui demandé. Une requête ne peut pas en poser un : le pont PSR-7
     * recopie **tous** les attributs de la requête Symfony (valeurs par défaut
     * de la route, valeurs posées par un écouteur, objets compris), mais un
     * paramètre de requête, qu'il s'appelle `mcp.cimd.discovered_client` ou non,
     * n'est jamais un attribut, et un objet ne vient pas d'une chaîne reçue :
     * la sûreté tient au test de type. Seul le code du serveur
     * (AuthorizationRequestValidator, après l'admission) dépose un McpClient,
     * et l'identifiant comparé empêche qu'il serve pour un autre `client_id`
     * que celui qu'il a jugé.
     *
     * **Au point d'autorisation seulement, une ligne `cimd` n'est jamais servie
     * seule.** Quand league lit la ligne en base (pas de client déclaré
     * admis pour ce `client_id`) et que cette ligne est un client déclaré
     * (ClientRegistration::Cimd) — laissée par une autorisation antérieure —,
     * le refus est `invalid_client`, sans redirection, comme celui de
     * l'admission. Deux déclencheurs réels : (a) une divergence entre le
     * `client_id` jugé par l'admission et celui que lit league (défense en
     * profondeur) ; (b) une ligne `cimd` dont l'identifiant n'a plus la forme
     * CIMD (écrite à la main, ou avant une règle plus stricte) : `admit()` rend
     * alors `null`, league lit la ligne, le garde refuse et l'audit écrit
     * l'hôte `unreadable`. (b) ne vaut que pour un identifiant encore valide
     * selon `McpClientId::fromString()` mais hors forme CIMD : un identifiant
     * que `McpClientId` refuse n'est jamais cherché par le dépôt league
     * (`ClientRepository::getClientEntity()`, `LeagueRepositoriesTest::
     * testAnUnreadableClientIdIsUnknownWithoutAQuery`) et donne un
     * `invalid_client` « client inconnu », sans cet audit ; le cas (b) est
     * joué de bout en bout par `CimdAuthorizationTest::
     * testAStoredCimdRowOutsideTheCimdFormIsRefusedAndAuditedAsUnreadable`.
     * Un domaine retiré de la liste n'atteint pas ce
     * garde : `admit()` lève `DomainNotAllowed` avant league, sans audit
     * (décision D-G). Cela ferme, à la racine, tout chemin par lequel league
     * lirait la ligne sans admission.
     *
     * **L'échange du code**, au point d'accès des jetons, n'a aucun attribut ni
     * admission (AbstractGrant::validateClient() l'appelle sans passer par
     * validateAuthorizationRequest()) : il lit toujours la ligne en base, que
     * le consentement a créée, et n'est pas gardé. La distinction tient à
     * l'attribut AUTHORIZATION_POINT_ATTRIBUTE, posé par le seul point
     * d'autorisation.
     *
     * @throws OAuthServerException `invalid_client` (client inconnu, ou ligne `cimd` non admise au point d'autorisation), `unauthorized_client` (grant non pris en charge)
     */
    protected function getClientEntityOrFail(string $clientId, ServerRequestInterface $request): ClientEntityInterface
    {
        $discovered = $request->getAttribute(CimdClientAdmissionInterface::REQUEST_ATTRIBUTE);

        if (!$discovered instanceof McpClient || $discovered->getClientId() !== $clientId) {
            $client = parent::getClientEntityOrFail($clientId, $request);

            if (true === $request->getAttribute(self::AUTHORIZATION_POINT_ATTRIBUTE)
                && $client instanceof OAuthClient
                && ClientRegistration::Cimd === $client->mcpClient()->registration()) {
                // Défense en profondeur (divergence de client_id) ou ligne `cimd`
                // à identifiant illisible : tracé, l'hôte de la ligne seul
                // (`unreadable` sans forme CIMD). Une panne de l'audit, au travers
                // de FailSafeAudit, ne change pas le refus.
                $host = $client->mcpClient()->cimdHost() ?? 'unreadable';
                $this->failSafeAudit->attempt(
                    fn () => $this->audit->mcpClientRowNotAdmitted($host),
                    self::STALE_ROW_REFUSAL_NOT_AUDITED,
                );
                $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));

                throw OAuthServerException::invalidClient($request);
            }

            return $client;
        }

        $client = new OAuthClient($discovered);

        // Le contrôle du parent, pour le client qu'il n'a pas lu.
        if (!$this->supportsGrantType($client, $this->getIdentifier())) {
            throw OAuthServerException::unauthorizedClient();
        }

        return $client;
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

        // La méthode admise est celle que les métadonnées annoncent, lue à la
        // même source (OAuthProfile).
        if (OAuthProfile::CODE_CHALLENGE_METHOD !== $this->getQueryStringParameter('code_challenge_method', $request)) {
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
