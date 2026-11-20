<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpClientProvider;
use DateTimeInterface;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Les clients MCP, vus du backoffice (#652, spec 0007 T13) : lecture seule à
 * cette tâche, réservée ROLE_SUPER par l'`access_control` `^/api/backoffice`
 * (aucune garde sur la ressource elle-même : la garde est unique, voir
 * `.claude/rules/backoffice-api.md`).
 *
 * L'identifiant exposé est celui de la ligne `mcp_client` (UUID), pas le
 * `client_id` public : ce dernier est un champ ordinaire (`clientId`), qui
 * peut être une URL (client CIMD) impropre à un segment de chemin.
 */
#[ApiResource(
    shortName: 'BackofficeMcpClient',
    // Garde les valeurs nulles dans la réponse (défaut API Platform : omises) :
    // le frontend attend toujours chaque clé.
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/mcp/clients',
            provider: BackofficeMcpClientProvider::class,
        ),
        // Point d'audit C6 : sans `Get` déclarée, API Platform en fabrique une
        // sur son gabarit par défaut (`/api/backoffice_mcp_clients/{id}`), un
        // second chemin non voulu vers les mêmes données.
        new Get(
            uriTemplate: '/backoffice/mcp/clients/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeMcpClientProvider::class,
        ),
    ],
)]
final readonly class BackofficeMcpClientResource
{
    /**
     * @param list<string>           $redirectUris
     * @param 'preregistered'|'cimd' $registration
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $name,
        public array $redirectUris,
        public string $registration,
        public string $registeredAt,
        public int $activeConnections,
    ) {
    }

    /**
     * Fabrique unique du DTO. Le nombre de connexions actives est reçu de
     * l'appelant (une requête `GROUP BY` pour tous les clients, jamais une par
     * client) : l'entité ne le connaît pas.
     */
    public static function fromClient(McpClient $client, int $activeConnections): self
    {
        return new self(
            id: $client->getId()->toRfc4122(),
            clientId: $client->getClientId(),
            name: $client->getName(),
            redirectUris: $client->redirectUris(),
            registration: $client->registration()->value,
            registeredAt: $client->registeredAt()->format(DateTimeInterface::ATOM),
            activeConnections: $activeConnections,
        );
    }
}
