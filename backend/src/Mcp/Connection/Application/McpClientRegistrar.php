<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

final readonly class McpClientRegistrar implements McpClientRegistrarInterface
{
    public function __construct(
        private McpClientRepositoryInterface $clients,
        private ClockInterface $clock,
    ) {
    }

    public function register(string $clientId, string $name, array $redirectUris): McpClient
    {
        if (null !== $this->clients->findOneByClientId($clientId)) {
            throw McpClientAlreadyRegisteredException::alreadyTaken();
        }

        $client = McpClient::register(
            $clientId,
            $name,
            array_map(static fn (RedirectUri $redirectUri): string => (string) $redirectUri, $redirectUris),
            $this->clock->now(),
        );

        try {
            $this->clients->add($client);
        } catch (UniqueConstraintViolationException) {
            // Deux enregistrements simultanés ont passé la lecture ci-dessus :
            // la contrainte unique de `client_id`, seule de la table hors clé
            // primaire (un UUID v7 neuf), est le dernier rempart. Même pratique
            // qu'ExperienceTechnologyRegistrar. La violation n'est pas gardée
            // en `previous` : elle n'apprend rien de plus à l'opérateur.
            throw McpClientAlreadyRegisteredException::takenMeanwhile();
        }

        return $client;
    }
}
