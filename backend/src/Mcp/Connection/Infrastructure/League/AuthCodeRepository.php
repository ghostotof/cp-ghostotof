<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Les codes d'autorisation de league, posés sur McpAuthorizationCode (#647).
 *
 * Le client reçoit une charge chiffrée par league ; la base n'en garde que
 * l'empreinte de l'identifiant. Un code est mort s'il est inconnu, révoqué
 * (league le révoque à l'échange : usage unique), ou si sa connexion l'est.
 */
final readonly class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private McpAuthorizationCodeRepositoryInterface $codes,
        private ConnectionOfToken $connectionOfToken,
        private ClockInterface $clock,
    ) {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode();
    }

    /**
     * @throws UniqueTokenIdentifierConstraintViolationException quand l'empreinte est déjà prise
     * @throws UnknownMcpConnectionException                     quand le code n'est pas celui d'une connexion
     * @throws McpConnectionOfAnotherClientException             quand la connexion est celle d'un autre client
     */
    public function persistNewAuthCode(#[SensitiveParameter] AuthCodeEntityInterface $authCodeEntity): void
    {
        $connection = $this->connectionOfToken->of($authCodeEntity->getUserIdentifier(), $authCodeEntity->getClient());

        try {
            $this->codes->add(new McpAuthorizationCode(
                $connection,
                TokenIdentifierHash::of($authCodeEntity->getIdentifier()),
                $authCodeEntity->getExpiryDateTime(),
            ));
        } catch (UniqueConstraintViolationException) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    public function revokeAuthCode(#[SensitiveParameter] string $codeId): void
    {
        $this->codes->revokeByHash(TokenIdentifierHash::of($codeId), $this->clock->now());
    }

    public function isAuthCodeRevoked(#[SensitiveParameter] string $codeId): bool
    {
        $code = $this->codes->findOneByHash(TokenIdentifierHash::of($codeId));

        return null === $code || $code->isRevoked() || $code->connection()->isRevoked();
    }
}
