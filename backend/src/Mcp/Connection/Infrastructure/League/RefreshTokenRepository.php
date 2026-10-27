<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Exception\RotatedRefreshTokenReusedException;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Infrastructure\Security\TrustedTier;
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
 * rotation révoque (#649). La connexion est celle de ce jeton d'accès.
 *
 * C'est ici, dans {@see self::isRefreshTokenRevoked()}, que le
 * rafraîchissement est jugé **sous le verrou de sa connexion** : league
 * l'appelle depuis sa validation, dans la transaction du point de jeton, avant
 * de révoquer l'ancien couple et d'émettre le nouveau.
 */
final readonly class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private McpRefreshTokenRepositoryInterface $tokens,
        private McpAccessTokenRepositoryInterface $accessTokens,
        private McpConnectionRepositoryInterface $connections,
        private ConnectionOfToken $connectionOfToken,
        private ConnectionLifetimes $lifetimes,
        private TrustedTier $trustedTier,
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

    /**
     * Le nom est celui de l'interface de league ; ce qu'elle juge est tout ce
     * qui rend un rafraîchissement irrecevable (#649), **sous le verrou de la
     * ligne de sa connexion**, dans la transaction du point de jeton :
     *
     *  1. le jeton, par son empreinte — inconnu, il est mort ;
     *  2. sa connexion, relue `FOR UPDATE` (`lockForUpdate()`) : deux
     *     rafraîchissements simultanés d'un même jeton se suivent désormais au
     *     lieu de se croiser, chacun lisant le jeton non révoqué avant que
     *     l'autre l'ait tourné (la course de league, fermée ici) — disparue,
     *     le jeton est mort ;
     *  3. le jeton **relu sous le verrou** (rereadUnderConnectionLock(), la
     *     relecture sous verrou du projet, #560) : une rotation qu'une requête
     *     concurrente a validée pendant l'attente se voit maintenant —
     *     disparu (purgé pendant l'attente), le jeton est mort ;
     *  4. la connexion révoquée : mort, sans seconde révocation ni audit ;
     *  5. le jeton révoqué sur une connexion active : il a été tourné, et
     *     revient — {@see RotatedRefreshTokenReusedException}, que le point
     *     de jeton rattrape après le ROLLBACK pour révoquer la connexion
     *     (OAuth 2.1 §4.3.1). Jugé avant l'échéance : un jeton tourné qui
     *     revient sur une connexion expirée mais pas encore purgée est une
     *     réutilisation aussi ;
     *  6. le jeton échu, la connexion expirée (McpConnection::isExpiredAt(),
     *     sur les durées configurées), le compte déchu du palier de confiance
     *     (TrustedTier, rôles stockés relus) : mort, rien d'émis — un client
     *     qui rafraîchirait en boucle n'accumule aucune ligne ;
     *  7. sinon le jeton est bon : le dernier usage de la connexion est noté,
     *     écrit seulement quand une heure s'est écoulée depuis le précédent
     *     (McpConnection::recordUseAt()).
     *
     * **Chaque jugement lit l'horloge de l'application** (ClockInterface, le
     * fuseau de Paris) — l'échéance du jeton comprise, exclusive comme celle
     * du jeton d'accès (`expiresAt <= now`). league compare aussi
     * `expire_time` à `time()` avant d'appeler ce dépôt : un second filet, le
     * même en production, que ce dépôt ne remplace pas et n'attend pas (deux
     * horloges, une seule qui juge ici).
     *
     * @throws RotatedRefreshTokenReusedException quand un jeton déjà tourné revient sur une connexion active
     */
    public function isRefreshTokenRevoked(#[SensitiveParameter] string $tokenId): bool
    {
        $hash = TokenIdentifierHash::of($tokenId);
        $token = $this->tokens->findOneByHash($hash);
        if (null === $token) {
            return true;
        }

        $connection = $this->connections->lockForUpdate($token->connection()->getId());
        if (null === $connection) {
            return true;
        }

        $token = $this->tokens->rereadUnderConnectionLock($token->getId());
        if (null === $token) {
            return true;
        }

        if ($connection->isRevoked()) {
            return true;
        }

        if ($token->isRevoked()) {
            throw RotatedRefreshTokenReusedException::forConnection($connection->getId());
        }

        $now = $this->clock->now();
        if ($token->expiresAt() <= $now || $connection->isExpiredAt($now, $this->lifetimes) || !$this->trustedTier->reaches($connection->account())) {
            return true;
        }

        if ($connection->recordUseAt($now)) {
            $this->connections->save($connection);
        }

        return false;
    }
}
