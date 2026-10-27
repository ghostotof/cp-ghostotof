<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\FormEncodedRequest;
use App\Mcp\Connection\Application\RevocationEndpointInterface;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use DateTimeImmutable;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use Symfony\Component\Uid\Uuid;

/**
 * La révocation d'un jeton (#647, RFC 7009), sur les jetons que league a
 * émis.
 *
 * - **Le client** se désigne par `client_id` dans le corps : un client public
 *   (les seuls servis, `revocation_endpoint_auth_methods_supported: none`),
 *   connu de ClientRepository — la même lecture que league, qui refuse sans
 *   requête un identifiant hors de la forme de la colonne. Sinon
 *   `invalid_client` (401).
 * - **Le jeton** est cherché d'abord comme jeton d'accès (l'identifiant d'un
 *   jeton opaque **est** le jeton, OpaqueAccessToken), puis comme jeton de
 *   rafraîchissement, déchiffré sous la clé de league
 *   (RefreshTokenPayloadReader). `token_type_hint` n'est pas lu : RFC 7009
 *   §2.1 permet de l'ignorer, et les deux recherches coûtent une requête
 *   chacune.
 * - **Révoqué s'il a été émis au client qui le présente**, et à lui seul :
 *   un jeton inconnu, illisible ou d'un autre client ne change rien, et la
 *   réponse est celle d'un succès (RFC 7009 §2.2) — elle ne dit pas qu'il
 *   existe. Un rafraîchissement emporte le jeton d'accès émis avec lui
 *   (RFC 7009 §2.1 le recommande), dans une transaction : jamais l'un sans
 *   l'autre. L'inverse non : révoquer un jeton d'accès laisse vivre son
 *   rafraîchissement (§2.1 ne fait que le permettre), qui en obtiendra un
 *   autre ; tout couper, c'est rendre le rafraîchissement (ci-dessous) ou
 *   révoquer la connexion (#650).
 * - **Un rafraîchissement révoqué révoque sa connexion** (#649, décision 3 ;
 *   RFC 7009 §2.1 : révoquer un rafraîchissement peut révoquer toute
 *   l'autorisation qu'il porte), cause `client`, dans la même transaction que
 *   ses deux jetons — par McpConnectionRepositoryInterface::revokeActive(),
 *   une instruction SQL que la base n'applique qu'une fois —, **dans l'ordre
 *   C → A → R** : la connexion d'abord, relue `FOR UPDATE`
 *   (lockForUpdate()), puis le jeton d'accès, puis le rafraîchissement.
 *   C avant les jetons est l'ordre des verrous du point de jeton (l'ordre
 *   inverse interbloquait une révocation croisant la rotation du même
 *   jeton) ; A avant R est l'ordre de qui touche les deux lignes : le grant
 *   de rafraîchissement de league, la cascade de la purge (supprimer A met R
 *   à jour) et les suppressions de la purge elle-même (sinon un interblocage
 *   avec celle-ci). Un rafraîchissement déjà tourné, rendu sur une
 *   connexion active, la révoque pour réutilisation (`refresh-token-reuse`),
 *   décidé sous le verrou. La ligne d'audit
 *   `mcp-connection-revoked` s'écrit **après** le COMMIT, et seulement si
 *   cette révocation-ci a eu lieu : une connexion déjà révoquée n'en reçoit
 *   pas de seconde. Conséquence voulue : un rafraîchissement révoqué qui
 *   revient sur une connexion encore active ne peut venir que d'une rotation,
 *   et sa réutilisation se reconnaît sans ambiguïté (RefreshTokenRepository).
 *   Un jeton d'accès rendu seul ne touche pas la connexion.
 *
 * Non traité, accepté : le temps de réponse diffère selon que le jeton est
 * trouvé, déchiffré ou non (une requête, un déchiffrement) ; le statut et le
 * corps, eux, sont les mêmes.
 */
final readonly class LeagueRevocationEndpoint implements RevocationEndpointInterface
{
    /** Les paramètres de RFC 7009 que ce point lit, chacun une seule fois. */
    private const array READ_PARAMETERS = ['token', 'token_type_hint', 'client_id'];

    public function __construct(
        private ClientRepository $clients,
        private McpAccessTokenRepositoryInterface $accessTokens,
        private McpRefreshTokenRepositoryInterface $refreshTokens,
        private McpConnectionRepositoryInterface $connections,
        private RefreshTokenPayloadReader $refreshTokenReader,
        private TransactionInterface $transaction,
        private SecurityAuditLoggerInterface $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function revoke(ServerRequestInterface $request): void
    {
        // Un formulaire, aucun paramètre répété ; les paramètres sont ceux
        // tirés de ce corps-là.
        $request = FormEncodedRequest::parse($request, self::READ_PARAMETERS);
        $body = $request->getParsedBody();
        $parameters = \is_array($body) ? $body : [];

        // Le client d'abord (RFC 7009 §2.1 : le serveur authentifie le client,
        // puis lit le jeton) : un client inconnu reçoit `invalid_client`, qu'il
        // présente un jeton ou non.
        $clientId = $parameters['client_id'] ?? null;
        $client = \is_string($clientId) ? $this->clients->getClientEntity($clientId) : null;

        if (null === $client) {
            throw OAuthServerException::invalidClient($request);
        }

        $token = $parameters['token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw OAuthServerException::invalidRequest('token', 'Check the `token` parameter: it is required.');
        }

        if (!$this->revokeAccessToken($token, $client->getIdentifier())) {
            $this->revokeRefreshToken($token, $client->getIdentifier());
        }
    }

    /**
     * @return bool vrai si le jeton est un jeton d'accès connu, de ce client
     *              ou non : la recherche s'arrête là
     */
    private function revokeAccessToken(#[SensitiveParameter] string $token, string $clientId): bool
    {
        $accessToken = $this->accessTokens->findOneByHash(TokenIdentifierHash::of($token));

        if (null === $accessToken) {
            return false;
        }

        if ($this->issuedTo($accessToken->connection(), $clientId)) {
            $this->accessTokens->revokeByHash($accessToken->tokenHash(), $this->clock->now());
        }

        return true;
    }

    private function revokeRefreshToken(#[SensitiveParameter] string $token, string $clientId): void
    {
        $identifier = $this->refreshTokenReader->identifierOf($token);
        $refreshToken = null === $identifier ? null : $this->refreshTokens->findOneByHash(TokenIdentifierHash::of($identifier));

        if (null === $refreshToken || !$this->issuedTo($refreshToken->connection(), $clientId)) {
            return;
        }

        $at = $this->clock->now();
        // Lus avant la transaction : leurs identifiants seuls servent dedans.
        // Le jeton d'accès est chargé avec le rafraîchissement (findOneByHash()
        // le joint), jamais un proxy : la purge peut supprimer sa ligne entre
        // cette lecture et la transaction, et un proxy initialisé ici lèverait
        // `EntityNotFoundException` (un 500) ; chargé, son empreinte se lit, et
        // la révocation d'une ligne déjà supprimée ne touche rien.
        $refreshTokenId = $refreshToken->getId();
        $tokenHash = $refreshToken->tokenHash();
        $accessTokenHash = $refreshToken->accessToken()?->tokenHash();
        $connectionId = $refreshToken->connection()->getId();

        $revoked = $this->transaction->run(
            fn (): ?RevokedConnection => $this->revokeUnderTheConnectionLock($connectionId, $refreshTokenId, $tokenHash, $accessTokenHash, $at),
        );

        // Après le COMMIT : jamais une ligne d'audit pour une révocation que
        // la base aurait annulée.
        if (null !== $revoked) {
            $this->auditLogger->mcpConnectionRevoked($revoked, $revoked->cause()->value);
        }
    }

    /**
     * Dans la transaction : la connexion, son jeton d'accès et le
     * rafraîchissement révoqués ensemble, **dans l'ordre C → A → R** — la
     * connexion d'abord (`FOR UPDATE`), puis le jeton d'accès A, puis le
     * rafraîchissement R.
     *
     * C avant les jetons est l'ordre des verrous du point de jeton : dans
     * l'ordre inverse, une révocation croisant la rotation du même jeton
     * tenait R et A en attendant la connexion que le rafraîchissement tenait
     * en attendant A : un interblocage, et la connexion restait active quand
     * PostgreSQL annulait la révocation (RevocationEndpointConcurrencyTest).
     *
     * A avant R est l'ordre de qui touche les deux lignes, et ils sont trois :
     * le grant de rafraîchissement de league révoque A puis R ; la purge
     * supprime A, dont la clé étrangère `ON DELETE SET NULL` sur
     * `mcp_refresh_token.access_token_id` met ensuite R à jour (sa cascade) ;
     * et la purge supprime ses jetons morts A puis R (ses propres `DELETE`,
     * depuis la relecture du lot B de #649 — avant, R puis A laissait un
     * cycle : une révocation sur une connexion déjà révoquée, dont les jetons
     * ne sont pas encore purgés, tenait A et attendait R quand la purge tenait
     * R et attendait A). Dans l'ordre R puis A, cette révocation tenait R et
     * attendait A quand la purge tenait A (son `DELETE`) et attendait R (la
     * mise à NULL de la cascade) : un interblocage (40P01), un 500, la
     * connexion laissée active et aucune ligne d'audit, quand la personne se
     * croit déconnectée (RevocationEndpointPurgeDeadlockTest).
     *
     * La cause se décide sous le verrou, sur le rafraîchissement relu par la
     * relecture sous verrou du projet (rereadUnderConnectionLock(), #560) :
     * déjà révoqué alors que la connexion est active, il a été tourné — rendu
     * ici, c'est le signal d'une réutilisation (décision 3), cause
     * `refresh-token-reuse` ; sinon `client`. Disparu pendant l'attente (la
     * purge l'a supprimé), la cause reste `client`.
     *
     * @return RevokedConnection|null `null` quand la connexion a disparu ou
     *                                était déjà révoquée : la première
     *                                révocation garde son instant, sa cause et
     *                                sa ligne d'audit
     */
    private function revokeUnderTheConnectionLock(Uuid $connectionId, Uuid $refreshTokenId, string $tokenHash, ?string $accessTokenHash, DateTimeImmutable $at): ?RevokedConnection
    {
        $connection = $this->connections->lockForUpdate($connectionId);
        // Relu sous le verrou, par la relecture du projet : une rotation
        // validée pendant l'attente est vue.
        $current = $this->refreshTokens->rereadUnderConnectionLock($refreshTokenId);

        $revoked = null;
        if (null !== $connection) {
            $rotated = !$connection->isRevoked() && null !== $current && $current->isRevoked();
            $revoked = $this->connections->revokeActive($connectionId, $rotated ? RevocationCause::RefreshTokenReuse : RevocationCause::Client, $at);
        }

        // Le jeton d'accès avant le rafraîchissement : A puis R, l'ordre de
        // qui touche les deux (le grant de rafraîchissement de league, la
        // cascade `ON DELETE SET NULL` de la purge, qui touche R après avoir
        // supprimé A, et les suppressions de la purge elle-même, A puis R).
        // L'ordre inverse interbloquait avec la purge, qui tient A et attend R
        // quand cette révocation tient R et attend A.
        // Le lien est nul quand le jeton d'accès a été purgé (`ON DELETE
        // SET NULL`) : il n'y a plus rien à révoquer.
        if (null !== $accessTokenHash) {
            $this->accessTokens->revokeByHash($accessTokenHash, $at);
        }

        $this->refreshTokens->revokeByHash($tokenHash, $at);

        return $revoked;
    }

    private function issuedTo(McpConnection $connection, string $clientId): bool
    {
        return $connection->client()->getClientId() === $clientId;
    }
}
