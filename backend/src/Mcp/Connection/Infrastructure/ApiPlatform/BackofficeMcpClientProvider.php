<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Get;
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
 * Lit les clients MCP pour le backoffice. La liste coûte deux requêtes : les
 * clients, puis les connexions actives de tous en un `GROUP BY` (quel que soit
 * leur nombre). Le détail (`Get`) en coûte deux : un client par sa clé, puis le
 * décompte de ses seules connexions actives. `Put` et `Delete` passent aussi
 * par le Provider d'item, mais n'en veulent que le client (une requête) : leur
 * écriture relit sous verrou, et le `PUT` recompte lui-même après sa révision.
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

        // Le décompte n'est lu que pour répondre : `Put` et `Delete` ne s'en servent pas.
        return $this->item($this->uriVariableUuid($uriVariables), $operation instanceof Get);
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
     * Une vraie lecture d'item : le client, puis — pour un `Get` seulement — ses
     * seules connexions actives. Un client inconnu rend `null` (404) **avant**
     * tout décompte. Pour une écriture, `activeConnections` vaut 0 sans avoir
     * été lu : la ressource ne sert qu'à prouver l'existence du client, aucune
     * réponse d'écriture ne la sérialise (le `PUT` rend la ressource du
     * Processor, le `DELETE` aucun corps).
     */
    private function item(Uuid $id, bool $withActiveConnections): ?BackofficeMcpClientResource
    {
        $client = $this->clients->findOneById($id);
        if (!$client instanceof McpClient) {
            return null;
        }

        return BackofficeMcpClientResource::fromClient($client, $withActiveConnections ? $this->connections->countActiveOfClient($id) : 0);
    }
}
