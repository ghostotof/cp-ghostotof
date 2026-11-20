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
use Symfony\Component\Uid\Uuid;

/**
 * Lit les clients MCP pour le backoffice, en deux requêtes dans les deux cas :
 * la liste lit les clients puis les connexions actives de tous en un `GROUP BY`
 * (quel que soit leur nombre) ; le détail lit un client par sa clé, puis le
 * décompte de ses seules connexions actives.
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
        if ($operation instanceof GetCollection) {
            return $this->collection();
        }

        return $this->item($this->uriVariableUuid($uriVariables));
    }

    /**
     * Deux requêtes : tous les clients, puis le décompte de tous en un `GROUP BY`.
     *
     * @return list<BackofficeMcpClientResource>
     */
    private function collection(): array
    {
        $counts = $this->connections->countActiveByClient();

        return array_map(
            static fn (McpClient $client): BackofficeMcpClientResource => BackofficeMcpClientResource::fromClient(
                $client,
                $counts[$client->getId()->toRfc4122()] ?? 0,
            ),
            $this->clients->all(),
        );
    }

    /**
     * Une vraie lecture d'item : le client, puis ses seules connexions
     * actives. Un client inconnu rend `null` (404) **avant** tout décompte.
     */
    private function item(Uuid $id): ?BackofficeMcpClientResource
    {
        $client = $this->clients->findOneById($id);
        if (!$client instanceof McpClient) {
            return null;
        }

        return BackofficeMcpClientResource::fromClient($client, $this->connections->countActiveOfClient($id));
    }
}
