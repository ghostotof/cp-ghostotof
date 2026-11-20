<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Lit les clients MCP pour le backoffice : deux requêtes, quel que soit leur
 * nombre (les clients, puis les connexions actives de tous en un `GROUP BY`).
 *
 * @implements ProviderInterface<BackofficeMcpClientResource>
 */
final readonly class BackofficeMcpClientProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private McpClientRepositoryInterface $clients,
        private McpConnectionRepositoryInterface $connections,
    ) {
    }

    /**
     * @return BackofficeMcpClientResource|list<BackofficeMcpClientResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeMcpClientResource|array|null
    {
        $counts = $this->connections->countActiveByClient();

        if ($operation instanceof GetCollection) {
            return array_map(
                static fn (McpClient $client): BackofficeMcpClientResource => BackofficeMcpClientResource::fromClient(
                    $client,
                    $counts[$client->getId()->toRfc4122()] ?? 0,
                ),
                $this->clients->all(),
            );
        }

        // Le dépôt n'offre pas de lecture simple par identifiant (`findOneByIdForUpdate`
        // verrouille et exige une transaction) : l'item se lit dans la liste, bornée
        // par nature (voir McpClientRepositoryInterface::all()). `null` = 404.
        $id = $this->uriVariableUuid($uriVariables)->toRfc4122();
        foreach ($this->clients->all() as $client) {
            if ($client->getId()->toRfc4122() === $id) {
                return BackofficeMcpClientResource::fromClient($client, $counts[$id] ?? 0);
            }
        }

        return null;
    }
}
