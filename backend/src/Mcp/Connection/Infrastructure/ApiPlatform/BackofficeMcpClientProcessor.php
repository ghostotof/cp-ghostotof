<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Mcp\Connection\Application\McpClientAdministratorInterface;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientRedirectsException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientInput;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientResource;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientRevisionInput;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * Écrit les clients MCP pour le backoffice (#652, spec 0007 T13) : enregistre,
 * révise et supprime.
 *
 * **Aucune transaction ici, à dessein** : `McpClientAdministrator` écrit, puis
 * audite après son COMMIT. Envelopper l'appel dans une transaction ferait
 * précéder le COMMIT par l'audit ; un COMMIT en échec laisserait alors un
 * « client supprimé » dans le journal que la base n'a pas. Le verrou
 * (`FOR UPDATE` du client, puis de ses connexions) est celui de
 * l'administrateur, pas du Provider : celui-ci ne lit que pour répondre 404 à
 * un client inconnu. Le Processor se borne à assembler les objets valeur et à
 * déléguer ; l'acteur du journal est lu dans le jeton de sécurité par l'audit
 * lui-même (`AuditOrigin::Request`, posé par l'administrateur).
 *
 * Le corps lu est un DTO d'entrée distinct de la ressource (`input:` de
 * `Post` et de `Put`), alors que le `process()` du trait type `$data` par son
 * paramètre de gabarit : celui-ci est donc l'union des entrées et de la
 * ressource, que chaque action rétrécit à son entrée.
 *
 * @implements ProcessorInterface<BackofficeMcpClientInput|BackofficeMcpClientRevisionInput|BackofficeMcpClientResource, BackofficeMcpClientInput|BackofficeMcpClientRevisionInput|BackofficeMcpClientResource|null>
 */
final readonly class BackofficeMcpClientProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeMcpClientInput|BackofficeMcpClientRevisionInput|BackofficeMcpClientResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private McpClientAdministratorInterface $clients,
        private McpConnectionRepositoryInterface $connections,
    ) {
    }

    /**
     * @param BackofficeMcpClientInput|BackofficeMcpClientRevisionInput|BackofficeMcpClientResource $data
     */
    private function create(mixed $data): BackofficeMcpClientResource
    {
        // L'opération `Post` déclare `input: BackofficeMcpClientInput` : toute autre
        // forme est un défaut de câblage de la ressource, pas une requête cliente.
        \assert($data instanceof BackofficeMcpClientInput);

        // `from…` (et non une garde) : `#[ValidMcpClientFields]` a déjà jugé chaque champ
        // et la liste (non vide, bornée) avant ce point. Une exception ici serait un
        // vrai défaut, à laisser remonter en 500 plutôt qu'à déguiser.
        $client = $this->clients->register(
            McpClientId::fromString($data->clientId),
            McpClientName::fromString($data->name),
            $this->redirectUris($data->redirectUris),
        );

        // Un client neuf n'a aucune connexion : 0, sans relire la base.
        return BackofficeMcpClientResource::fromClient($client, 0);
    }

    /**
     * @param BackofficeMcpClientInput|BackofficeMcpClientRevisionInput|BackofficeMcpClientResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeMcpClientResource
    {
        // `Put` déclare `input: BackofficeMcpClientRevisionInput`, comme `create()` pour `Post`.
        \assert($data instanceof BackofficeMcpClientRevisionInput);

        // Un client inconnu ou déclaré par son document lève ici (404, 409 typé) : aucune
        // écriture, aucun audit. Le nom et les redirections sont remplacés d'un bloc, sans
        // couper de connexion (D-E).
        $client = $this->clients->revise(
            $id,
            McpClientName::fromString($data->name),
            $this->redirectUris($data->redirectUris),
        );

        // Réviser ne touche pas aux connexions : leur nombre est relu (une requête) pour
        // que la réponse dise l'état courant, pas celui que le Provider a lu avant.
        return BackofficeMcpClientResource::fromClient($client, $this->connections->countActiveOfClient($client->getId()));
    }

    private function delete(Uuid $id): void
    {
        // Le client inconnu (supprimé entre le Provider et ici) lève
        // `McpClientNotFoundException` : 404. Le nombre de connexions coupées est
        // dans le journal (`cutConnections`), la réponse est un 204 sans corps.
        $this->clients->remove($id);
    }

    /**
     * @param list<string> $redirectUris
     *
     * @return non-empty-list<RedirectUri>
     *
     * @throws InvalidMcpClientRedirectsException jamais en pratique : le validateur a refusé une liste vide
     */
    private function redirectUris(array $redirectUris): array
    {
        $redirects = array_map(RedirectUri::fromString(...), $redirectUris);

        // Le type `non-empty-list` de `register()` n'est pas déductible du validateur :
        // la garde le dit à l'analyse statique, et un contournement du validateur
        // sortirait en 422 plutôt qu'en erreur de type.
        if ([] === $redirects) {
            throw InvalidMcpClientRedirectsException::none();
        }

        return $redirects;
    }
}
