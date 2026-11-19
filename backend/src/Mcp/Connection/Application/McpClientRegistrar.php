<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Shared\Infrastructure\Doctrine\ViolatedUniqueIndex;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

final readonly class McpClientRegistrar implements McpClientRegistrarInterface
{
    public function __construct(
        private McpClientRepositoryInterface $clients,
        private ClockInterface $clock,
    ) {
    }

    public function register(McpClientId $clientId, McpClientName $name, array $redirectUris): McpClient
    {
        if (null !== $this->clients->findOneByClientId((string) $clientId)) {
            throw McpClientAlreadyRegisteredException::alreadyTaken();
        }

        $client = McpClient::register($clientId, $name, $redirectUris, $this->clock->now());

        try {
            $this->clients->add($client);
        } catch (UniqueConstraintViolationException $violation) {
            // Deux enregistrements simultanés ont passé la lecture ci-dessus :
            // la contrainte unique de `client_id` est le dernier rempart. Seule
            // elle dit « identifiant pris » ; toute autre unicité (une
            // contrainte ajoutée un jour sans ce code) remonte telle quelle,
            // aucune cause n'étant affirmée à tort. La violation reconnue n'est
            // pas gardée en `previous` : elle n'apprend rien de plus à
            // l'opérateur.
            if (McpClient::CLIENT_ID_UNIQUE_INDEX !== ViolatedUniqueIndex::of($violation)) {
                throw $violation;
            }

            throw McpClientAlreadyRegisteredException::takenMeanwhile();
        }

        return $client;
    }
}
