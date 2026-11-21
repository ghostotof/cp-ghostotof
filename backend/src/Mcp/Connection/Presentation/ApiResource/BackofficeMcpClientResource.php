<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpClientProcessor;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpClientProvider;
use DateTimeInterface;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Les clients MCP, vus du backoffice (#652, spec 0007 T13) : lecture,
 * enregistrement, révision (nom et redirections) et suppression, réservés
 * ROLE_SUPER par l'`access_control` `^/api/backoffice` (aucune garde sur la
 * ressource elle-même : la garde est unique, voir
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
        // Un attribut que l'entrée ne porte pas (`registration`, une faute de frappe
        // sur un nom de champ…) est refusé (400, MalformedRequestBodyException) au
        // lieu d'être ignoré avec un 201 : même règle que le `Put`.
        new Post(
            uriTemplate: '/backoffice/mcp/clients',
            status: 201,
            denormalizationContext: ['allow_extra_attributes' => false],
            input: BackofficeMcpClientInput::class,
            processor: BackofficeMcpClientProcessor::class,
        ),
        // `provider:` explicite : sans lui, API Platform ne trouve pas l'élément (404).
        // Le Provider sert de lecture avant écriture (404 d'un client inconnu) ; la
        // sérialisation des écritures est sous le verrou de l'administrateur.
        // Le corps n'est pas la ressource : un `clientId` ne se révise pas, et un
        // attribut que l'entrée ne porte pas (`clientId` compris) est refusé (400,
        // MalformedRequestBodyException) au lieu d'être ignoré avec un 200. Ce
        // contexte est fusionné avec le défaut global (`api_allow_update: false`).
        new Put(
            uriTemplate: '/backoffice/mcp/clients/{id}',
            requirements: ['id' => Requirement::UUID],
            status: 200,
            denormalizationContext: ['allow_extra_attributes' => false],
            input: BackofficeMcpClientRevisionInput::class,
            provider: BackofficeMcpClientProvider::class,
            processor: BackofficeMcpClientProcessor::class,
        ),
        // Supprime le client et coupe ses connexions (D-A) : 204, sans corps.
        new Delete(
            uriTemplate: '/backoffice/mcp/clients/{id}',
            requirements: ['id' => Requirement::UUID],
            status: 204,
            provider: BackofficeMcpClientProvider::class,
            processor: BackofficeMcpClientProcessor::class,
        ),
    ],
)]
final readonly class BackofficeMcpClientResource
{
    /**
     * @param list<string>           $redirectUris
     * @param 'preregistered'|'cimd' $registration
     * @param bool                   $shadowsVerifiedDomain la ligne est `preregistered` et son
     *                                                      `client_id` relève d'un domaine vérifié
     *                                                      CIMD : elle masque ce domaine, le serveur
     *                                                      OAuth sert le client à partir d'elle et
     *                                                      jamais de son document de métadonnées
     *                                                      (#651). Le backoffice la signale pour
     *                                                      qu'elle soit supprimée ; une ligne `cimd`
     *                                                      ne masque rien, par construction
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $name,
        public array $redirectUris,
        public string $registration,
        public string $registeredAt,
        public int $activeConnections,
        public bool $shadowsVerifiedDomain,
    ) {
    }

    /**
     * Fabrique unique du DTO, partagée par le Provider et le Processor. Le
     * nombre de connexions actives est reçu de l'appelant (une requête
     * `GROUP BY` pour tous les clients, jamais une par client) : l'entité ne le
     * connaît pas. Le masquage d'un domaine vérifié est calculé ici, une seule
     * fois pour toutes les opérations, par la règle même qu'appliquent
     * `ClientRepository` et le point de jeton ({@see AllowedClientDomains::isShadowedBy()}) :
     * en mémoire, sur la liste fermée des domaines, sans aucune requête.
     */
    public static function fromClient(McpClient $client, int $activeConnections, AllowedClientDomains $domains): self
    {
        return new self(
            id: $client->getId()->toRfc4122(),
            clientId: $client->getClientId(),
            name: $client->getName(),
            redirectUris: $client->redirectUris(),
            registration: $client->registration()->value,
            registeredAt: $client->registeredAt()->format(DateTimeInterface::ATOM),
            activeConnections: $activeConnections,
            shadowsVerifiedDomain: $domains->isShadowedBy($client->registration(), $client->getClientId()),
        );
    }
}
