<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientNotFoundException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
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
 * Le masquage d'un domaine vérifié (`shadowsVerifiedDomain`) n'ajoute aucune
 * requête : il se juge en mémoire, sur la liste fermée des domaines.
 *
 * @implements ProviderInterface<BackofficeMcpClientResource>
 */
final readonly class BackofficeMcpClientProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private McpClientRepositoryInterface $clients,
        private McpConnectionRepositoryInterface $connections,
        private AllowedClientDomains $domains,
    ) {
    }

    /**
     * @return BackofficeMcpClientResource|list<BackofficeMcpClientResource>
     *
     * @throws McpClientNotFoundException le client de l'URI n'existe pas (404 typé)
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeMcpClientResource|array
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
        // Une variable locale, capturée par la fonction fléchée : celle-ci reste `static`.
        $domains = $this->domains;

        return array_map(
            static fn (McpClient $client): BackofficeMcpClientResource => BackofficeMcpClientResource::fromClient(
                $client,
                $counts[$client->getId()->toRfc4122()] ?? 0,
                $domains,
            ),
            $this->clients->all(),
        );
    }

    /**
     * Une vraie lecture d'item : le client, puis — pour un `Get` seulement — ses
     * seules connexions actives. Un client inconnu lève
     * {@see McpClientNotFoundException} **avant** tout décompte : le même 404 typé
     * que la course où le client disparaît entre ce Provider et l'écriture
     * (`McpClientAdministrator`), plutôt que le 404 générique d'API Platform
     * qu'un `null` produirait. Le message est fixe, sans l'identifiant demandé. Pour une écriture, `activeConnections` vaut 0 sans avoir
     * été lu : la ressource ne sert qu'à prouver l'existence du client, aucune
     * réponse d'écriture ne la sérialise (le `PUT` rend la ressource du
     * Processor, le `DELETE` aucun corps).
     */
    private function item(Uuid $id, bool $withActiveConnections): BackofficeMcpClientResource
    {
        $client = $this->clients->findOneById($id) ?? throw McpClientNotFoundException::forTheCaller();

        return BackofficeMcpClientResource::fromClient(
            $client,
            $withActiveConnections ? $this->connections->countActiveOfClient($id) : 0,
            $this->domains,
        );
    }
}
