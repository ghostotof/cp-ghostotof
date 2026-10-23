<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * L'« utilisateur » de league est la connexion MCP (#647), jamais le compte :
 * chaque code et chaque jeton se rattache à une connexion, et meurt avec elle
 * (une révocation par la personne, une expiration, une réutilisation). Son
 * identifiant est l'UUID de la connexion, au format RFC 4122, que les dépôts
 * de league relisent pour retrouver la connexion.
 *
 * Le compte n'apparaît donc dans aucune charge chiffrée remise au client.
 */
final readonly class ConnectionAsUser implements UserEntityInterface
{
    /**
     * @param non-empty-string $connectionId
     */
    private function __construct(
        private string $connectionId,
    ) {
    }

    public static function of(McpConnection $connection): self
    {
        /** @var non-empty-string $connectionId un UUID au format RFC 4122, 36 caractères */
        $connectionId = $connection->getId()->toRfc4122();

        return new self($connectionId);
    }

    /**
     * @return non-empty-string
     */
    public function getIdentifier(): string
    {
        return $this->connectionId;
    }
}
