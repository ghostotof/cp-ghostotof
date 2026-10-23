<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\QueryParameterPollution;
use App\Mcp\Connection\Application\RevocationEndpointInterface;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

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
 *   (RFC 7009 §2.1 le permet), dans une transaction : jamais l'un sans
 *   l'autre.
 * - **La connexion n'est pas révoquée** : la révocation d'une connexion, et
 *   sa ligne d'audit « connexion révoquée », sont l'objet des tâches 10 et
 *   11 de la spec (#649, #650). Ici, un client rend un jeton dont il n'a plus
 *   l'usage, rien de plus.
 *
 * Non traité, accepté : le temps de réponse diffère selon que le jeton est
 * trouvé, déchiffré ou non (une requête, un déchiffrement) ; le statut et le
 * corps, eux, sont les mêmes.
 */
final readonly class LeagueRevocationEndpoint implements RevocationEndpointInterface
{
    /** Les paramètres de RFC 7009 que ce point lit, chacun une seule fois. */
    public const array READ_PARAMETERS = ['token', 'token_type_hint', 'client_id'];

    public function __construct(
        private ClientRepository $clients,
        private McpAccessTokenRepositoryInterface $accessTokens,
        private McpRefreshTokenRepositoryInterface $refreshTokens,
        private RefreshTokenPayloadReader $refreshTokenReader,
        private TransactionInterface $transaction,
        private ClockInterface $clock,
    ) {
    }

    public function revoke(ServerRequestInterface $request): void
    {
        // Lu sur le corps brut, que le pont PSR-7 recopie : les paramètres
        // analysés n'en gardent déjà plus qu'une occurrence.
        $polluted = QueryParameterPollution::firstPollutedName((string) $request->getBody(), self::READ_PARAMETERS);

        if (null !== $polluted) {
            throw OAuthServerException::invalidRequest($polluted, \sprintf('Check the `%s` parameter: it must be given once, under its exact name.', $polluted));
        }

        $body = $request->getParsedBody();
        $parameters = \is_array($body) ? $body : [];
        $token = $parameters['token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw OAuthServerException::invalidRequest('token', 'Check the `token` parameter: it is required.');
        }

        $clientId = $parameters['client_id'] ?? null;
        $client = \is_string($clientId) ? $this->clients->getClientEntity($clientId) : null;

        if (null === $client) {
            throw OAuthServerException::invalidClient($request);
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
        // Lu avant la transaction : son empreinte seule sert dedans.
        $accessTokenHash = $refreshToken->accessToken()?->tokenHash();

        $this->transaction->run(function () use ($refreshToken, $accessTokenHash, $at): void {
            $this->refreshTokens->revokeByHash($refreshToken->tokenHash(), $at);

            // Le lien est nul quand le jeton d'accès a été purgé (`ON DELETE
            // SET NULL`) : il n'y a plus rien à révoquer.
            if (null !== $accessTokenHash) {
                $this->accessTokens->revokeByHash($accessTokenHash, $at);
            }
        });
    }

    private function issuedTo(McpConnection $connection, string $clientId): bool
    {
        return $connection->client()->getClientId() === $clientId;
    }
}
