<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Security;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\BearerRefusal;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Infrastructure\League\TokenIdentifierHash;
use App\Mcp\Infrastructure\Http\UnauthorizedAnswers;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * L'authentificateur du firewall `mcp` (#648, ADR 0006 D4, spec 0007
 * « Authentificateur ») : le jeton d'accès opaque d'une connexion MCP,
 * présenté en `Authorization: Bearer`, **relu en base à chaque requête** —
 * une connexion révoquée, expirée, ou un compte qui perd le palier de
 * confiance cessent d'ouvrir le corpus à la requête suivante, ce que le JWT
 * du site ne fait pas (ADR 0003, risque accepté).
 *
 * **Le seul en-tête lu est `Authorization`, au schéma `Bearer`**, insensible à
 * la casse (RFC 7235 §2.1), suivi d'une espace ou seul ; jamais un cookie —
 * c'est ce qui rend sûre l'exclusion de `/api/mcp` de la garde CSRF. Un autre
 * schéma, notamment le `Basic` que le Basic Auth de l'Ingress de préprod fait
 * envoyer sur chaque requête, n'est pas un jeton présenté : `supports()` le
 * laisse passer, la requête reste anonyme (phase 1 inchangée).
 *
 * Le jugement, dans cet ordre, la première cause trouvée l'emportant
 * ({@see BearerRefusal}) :
 *
 *  1. la forme, **avant toute requête** : exactement 80 hexadécimaux
 *     minuscules, la forme d'un identifiant de league (40 octets aléatoires)
 *     — `Bearer` seul, une espace de trop, 10 Kio, une majuscule ne coûtent
 *     ni empreinte utile ni aller-retour vers la base (`Malformed`) ;
 *  2. l'empreinte (TokenIdentifierHash, celle sous laquelle le jeton a été
 *     stocké) et **une** requête, jeton, connexion, compte et client joints
 *     (McpAccessTokenRepositoryInterface::findOneByHash()) — `Unknown` ;
 *  3. ce que le domaine du jeton juge à cet instant pour l'audience
 *     `/api/mcp` (McpAccessToken::refusalAt()) : jeton révoqué, expiré —
 *     league ne regarde pas l'échéance d'un jeton opaque —, d'une autre
 *     audience, connexion révoquée, expirée (sur les durées configurées,
 *     ConnectionLifetimes, #649) ;
 *  4. le palier du compte (TrustedTier, le même juge qu'au rafraîchissement,
 *     #649) : ROLE_TRUSTED atteint par la `role_hierarchy` depuis les rôles
 *     stockés (un ROLE_SUPER sans ROLE_TRUSTED stocké passe, comme partout
 *     sur le site ; le palier de base non) — `AccountNotTrusted`.
 *
 * **Aucune écriture** : ni `last_used_at` (écrit au rafraîchissement, #649),
 * ni rien d'autre (ADR 0006 D4). Le compte n'est chargé par aucun autre
 * chemin avant la requête du jeton : une jointure ne rafraîchit pas une
 * instance que l'EntityManager gérerait déjà, et c'est ce qui garantit que
 * les rôles lus sont ceux de la base à cette requête.
 *
 * **Un jeton présenté et refusé est un 401 sur toute requête** de
 * `/api/mcp` (décision de l'auteur du 2026-10-24) : `initialize`, outil
 * public ou nominatif, marqueur `openai/` ou non — RFC 6750 §3.1, c'est ce qui
 * fait rafraîchir le client. Sauf ce qui est répondu avant le firewall
 * (priorité 8) : les refus d'avant le SDK
 * ({@see \App\Mcp\Infrastructure\Http\InboundMessageRefusalListener}, 15, un
 * 400) et les 404/405 du routeur (32) gardent leur réponse. Sans corps,
 * `WWW-Authenticate` portant `error="invalid_token"`
 * ({@see \App\Mcp\Connection\Application\BearerChallenge::refusedToken()}),
 * `no-store`, et la même pour toutes les causes : la cause n'est nommée que
 * dans l'exception, que le canal `security` journalise
 * (McpBearerRefusedException). La réponse est celle d'UnauthorizedAnswers,
 * qui l'inscrit pour le journal d'appels (`mcp_usage`) comme une réponse du
 * serveur.
 */
final class McpBearerAuthenticator extends AbstractAuthenticator
{
    /** Le schéma `Bearer`, insensible à la casse, suivi d'une espace ou de la fin de l'en-tête. */
    private const string BEARER_SCHEME = '/^bearer(?: |\z)/i';

    /** La longueur du schéma et de son espace, ce qui précède le jeton. */
    private const int SCHEME_PREFIX_LENGTH = 7;

    /** Un identifiant de jeton de league : 40 octets aléatoires en hexadécimal minuscule. */
    private const string ISSUED_TOKEN_SHAPE = '/^[0-9a-f]{80}\z/';

    public function __construct(
        private readonly McpAccessTokenRepositoryInterface $accessTokens,
        private readonly ClockInterface $clock,
        private readonly OAuthEndpoints $endpoints,
        private readonly TrustedTier $trustedTier,
        private readonly UnauthorizedAnswers $unauthorized,
        private readonly ConnectionLifetimes $lifetimes,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->isBearer((string) $request->headers->get('Authorization'));
    }

    /**
     * @throws McpBearerRefusedException quel que soit le refus, sa cause dans le message
     */
    public function authenticate(Request $request): SelfValidatingPassport
    {
        $accessToken = $this->presentedToken((string) $request->headers->get('Authorization'));

        if (1 !== preg_match(self::ISSUED_TOKEN_SHAPE, $accessToken)) {
            throw McpBearerRefusedException::because(BearerRefusal::Malformed);
        }

        $token = $this->accessTokens->findOneByHash(TokenIdentifierHash::of($accessToken));

        if (null === $token) {
            throw McpBearerRefusedException::because(BearerRefusal::Unknown);
        }

        $refusal = $token->refusalAt($this->clock->now(), $this->endpoints->resource(), $this->lifetimes);

        if (null !== $refusal) {
            throw McpBearerRefusedException::because($refusal);
        }

        $connection = $token->connection();

        if (!$this->trustedTier->reaches($connection->account())) {
            throw McpBearerRefusedException::because(BearerRefusal::AccountNotTrusted);
        }

        $user = McpConnectionUser::ofConnection($connection);

        // Le badge porte son chargeur : l'utilisateur est la ligne relue
        // ci-dessus, jamais un second chargement par McpConnectionUserProvider.
        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): McpConnectionUser => $user));
    }

    /** La requête continue jusqu'au serveur MCP, sous l'identité de la connexion. */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized->refusedToken();
    }

    private function isBearer(#[SensitiveParameter] string $authorization): bool
    {
        return 1 === preg_match(self::BEARER_SCHEME, $authorization);
    }

    /** Ce qui suit `Bearer ` tel quel : vide pour `Bearer` seul, rien n'est rogné. */
    private function presentedToken(#[SensitiveParameter] string $authorization): string
    {
        return substr($authorization, self::SCHEME_PREFIX_LENGTH);
    }
}
