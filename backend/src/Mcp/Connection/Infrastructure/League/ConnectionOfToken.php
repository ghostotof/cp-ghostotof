<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La connexion MCP à laquelle league rattache un code ou un jeton (#647) :
 * son « utilisateur » est l'UUID de la connexion (ConnectionAsUser), le
 * client du jeton doit être celui de la connexion, et la connexion ne doit
 * pas être révoquée. Lue telle que l'EntityManager la tient : une révocation
 * écrite en DQL dans la même requête n'est vue qu'après un `clear()`.
 *
 * Écrit une fois pour les trois dépôts de league : chaque refus est un
 * défaut du serveur, nommé, jamais une erreur OAuth.
 */
final readonly class ConnectionOfToken
{
    public function __construct(
        private McpConnectionRepositoryInterface $connections,
    ) {
    }

    /**
     * @throws UnknownMcpConnectionException         sans identifiant, hors UUID, ou connexion inconnue
     * @throws McpConnectionOfAnotherClientException quand la connexion est celle d'un autre client
     * @throws RevokedMcpConnectionException         quand la connexion est déjà révoquée
     */
    public function of(?string $userIdentifier, ClientEntityInterface $client): McpConnection
    {
        if (null === $userIdentifier || !Uuid::isValid($userIdentifier)) {
            throw UnknownMcpConnectionException::notAConnectionIdentifier();
        }

        $connection = $this->connections->findOneById(Uuid::fromString($userIdentifier))
            ?? throw UnknownMcpConnectionException::unknown();

        if ($connection->client()->getClientId() !== $client->getIdentifier()) {
            throw McpConnectionOfAnotherClientException::forTokenClient();
        }

        if ($connection->isRevoked()) {
            throw RevokedMcpConnectionException::atIssuance();
        }

        return $connection;
    }
}
