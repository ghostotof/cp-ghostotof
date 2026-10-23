<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Les jetons d'accès de league, posés sur McpAccessToken (#647).
 *
 * L'identifiant d'un jeton opaque **est** le jeton présenté en `Bearer` : la
 * base n'en garde que l'empreinte (TokenIdentifierHash), avec l'audience de
 * la ressource MCP (RFC 8707) et l'échéance. Un jeton est mort s'il est
 * inconnu, révoqué, ou si sa connexion l'est.
 */
final readonly class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private McpAccessTokenRepositoryInterface $tokens,
        private ConnectionOfToken $connectionOfToken,
        private OAuthEndpoints $endpoints,
        private ClockInterface $clock,
    ) {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new OpaqueAccessToken();
        $token->setClient($clientEntity);

        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        // Sans utilisateur, le jeton n'est rattaché à rien : persistNewAccessToken()
        // le refusera (UnknownMcpConnectionException).
        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $token->setUserIdentifier($userIdentifier);
        }

        return $token;
    }

    /**
     * @throws UniqueTokenIdentifierConstraintViolationException quand l'empreinte est déjà prise
     * @throws UnknownMcpConnectionException                     quand le jeton n'est pas celui d'une connexion
     * @throws McpConnectionOfAnotherClientException             quand la connexion est celle d'un autre client
     */
    public function persistNewAccessToken(#[SensitiveParameter] AccessTokenEntityInterface $accessTokenEntity): void
    {
        $connection = $this->connectionOfToken->of($accessTokenEntity->getUserIdentifier(), $accessTokenEntity->getClient());

        try {
            $this->tokens->add(new McpAccessToken(
                $connection,
                TokenIdentifierHash::of($accessTokenEntity->getIdentifier()),
                $this->endpoints->resource(),
                $accessTokenEntity->getExpiryDateTime(),
            ));
        } catch (UniqueConstraintViolationException) {
            // L'exception attendue par league pour tirer un autre identifiant.
            // La violation n'est pas gardée : son `DETAIL` cite l'empreinte.
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    public function revokeAccessToken(#[SensitiveParameter] string $tokenId): void
    {
        $this->tokens->revokeByHash(TokenIdentifierHash::of($tokenId), $this->clock->now());
    }

    public function isAccessTokenRevoked(#[SensitiveParameter] string $tokenId): bool
    {
        $token = $this->tokens->findOneByHash(TokenIdentifierHash::of($tokenId));

        return null === $token || $token->isRevoked() || $token->connection()->isRevoked();
    }
}
