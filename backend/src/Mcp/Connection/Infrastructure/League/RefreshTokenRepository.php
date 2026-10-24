<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Les jetons de rafraîchissement de league, posés sur McpRefreshToken (#647).
 *
 * Le client reçoit une charge chiffrée par league ; la base n'en garde que
 * l'empreinte de l'identifiant, et le jeton d'accès émis avec lui, que la
 * rotation (#649) révoquera. La connexion est celle de ce jeton d'accès.
 */
final readonly class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private McpRefreshTokenRepositoryInterface $tokens,
        private McpAccessTokenRepositoryInterface $accessTokens,
        private ConnectionOfToken $connectionOfToken,
        private ClockInterface $clock,
    ) {
    }

    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshToken();
    }

    /**
     * @throws UniqueTokenIdentifierConstraintViolationException quand l'empreinte est déjà prise
     * @throws UnknownMcpConnectionException                     quand le jeton n'est pas celui d'une connexion
     * @throws McpConnectionOfAnotherClientException             quand la connexion est celle d'un autre client
     * @throws RevokedMcpConnectionException                     quand la connexion est déjà révoquée
     * @throws AccessTokenOfRefreshTokenNotFoundException        quand le jeton d'accès n'est pas en base
     */
    public function persistNewRefreshToken(#[SensitiveParameter] RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $accessToken = $refreshTokenEntity->getAccessToken();
        $connection = $this->connectionOfToken->of($accessToken->getUserIdentifier(), $accessToken->getClient());
        // Le jeton d'accès vient d'être enregistré dans la même requête
        // (league émet l'un puis l'autre) : absent, c'est un défaut du serveur,
        // jamais un rafraîchissement enregistré sans son lien.
        $persistedAccessToken = $this->accessTokens->findOneByHash(TokenIdentifierHash::of($accessToken->getIdentifier()))
            ?? throw AccessTokenOfRefreshTokenNotFoundException::notPersisted();

        try {
            $this->tokens->add(new McpRefreshToken(
                $connection,
                TokenIdentifierHash::of($refreshTokenEntity->getIdentifier()),
                $persistedAccessToken,
                $refreshTokenEntity->getExpiryDateTime(),
            ));
        } catch (UniqueConstraintViolationException) {
            // Nomme la cause, rien de plus : le flush refusé a fermé
            // l'EntityManager, le nouvel essai que league tenterait échouerait.
            // La violation n'est pas gardée : son DETAIL cite l'empreinte.
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    public function revokeRefreshToken(#[SensitiveParameter] string $tokenId): void
    {
        $this->tokens->revokeByHash(TokenIdentifierHash::of($tokenId), $this->clock->now());
    }

    public function isRefreshTokenRevoked(#[SensitiveParameter] string $tokenId): bool
    {
        $token = $this->tokens->findOneByHash(TokenIdentifierHash::of($tokenId));

        return null === $token || $token->isRevoked() || $token->connection()->isRevoked();
    }
}
