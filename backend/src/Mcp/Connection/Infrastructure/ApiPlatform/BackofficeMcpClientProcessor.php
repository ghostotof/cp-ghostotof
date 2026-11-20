<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Mcp\Connection\Application\McpClientAdministratorInterface;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientRedirectsException;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientInput;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientResource;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use App\Shared\Infrastructure\ApiPlatform\UnsupportedOperationException;
use Symfony\Component\Uid\Uuid;

/**
 * Écrit les clients MCP pour le backoffice (#652, spec 0007 T13).
 *
 * **Aucune transaction ici, à dessein** : `McpClientRegistrar` écrit puis
 * audite après son `flush` (COMMIT). Envelopper l'appel dans une transaction
 * ferait précéder le COMMIT par l'audit ; un COMMIT en échec laisserait alors
 * un « client enregistré » dans le journal que la base n'a pas. Le Processor se
 * borne à assembler les objets valeur et à déléguer ; l'acteur du journal est
 * lu dans le jeton de sécurité par l'audit lui-même (`AuditOrigin::Request`,
 * posé par l'administrateur).
 *
 * `update()` et `delete()` ne sont livrés qu'à la Task 17B.
 *
 * Le corps lu est un DTO d'entrée distinct de la ressource (`input:` de
 * l'opération `Post`), alors que le `process()` du trait type `$data` par son
 * paramètre de gabarit : celui-ci est donc l'union de l'entrée et de la
 * ressource, que chaque action rétrécit à son entrée.
 *
 * @implements ProcessorInterface<BackofficeMcpClientInput|BackofficeMcpClientResource, BackofficeMcpClientInput|BackofficeMcpClientResource|null>
 */
final readonly class BackofficeMcpClientProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeMcpClientInput|BackofficeMcpClientResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private McpClientAdministratorInterface $clients,
    ) {
    }

    /**
     * @param BackofficeMcpClientInput|BackofficeMcpClientResource $data
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
            $this->redirectUris($data),
        );

        // Un client neuf n'a aucune connexion : 0, sans relire la base.
        return BackofficeMcpClientResource::fromClient($client, 0);
    }

    /** Task 17B. Les paramètres suivent la signature abstraite du trait (voir `rector.php`). */
    private function update(Uuid $id, mixed $data): BackofficeMcpClientResource
    {
        throw UnsupportedOperationException::forOperation(new Put());
    }

    /**
     * Task 17B. Même signature que `update()` : celle du trait. `void` et non
     * `never` : le `return null` du trait deviendrait du code inatteignable.
     */
    private function delete(Uuid $id): void
    {
        throw UnsupportedOperationException::forOperation(new Delete());
    }

    /**
     * @return non-empty-list<RedirectUri>
     *
     * @throws InvalidMcpClientRedirectsException jamais en pratique : le validateur a refusé une liste vide
     */
    private function redirectUris(BackofficeMcpClientInput $data): array
    {
        $redirects = array_map(RedirectUri::fromString(...), $data->redirectUris);

        // Le type `non-empty-list` de `register()` n'est pas déductible du validateur :
        // la garde le dit à l'analyse statique, et un contournement du validateur
        // sortirait en 422 plutôt qu'en erreur de type.
        if ([] === $redirects) {
            throw InvalidMcpClientRedirectsException::none();
        }

        return $redirects;
    }
}
