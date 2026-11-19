<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use Throwable;

/**
 * Le dépôt factice de McpClientRegistrarTest : `existing` répond à la
 * lecture, `failure` est levée par l'ajout, `added` garde ce qui a été ajouté.
 * Une classe nommée plutôt qu'anonyme, pour que PHPStan connaisse `added`.
 *
 * Les deux méthodes des clients déclarés (#651) suivent le contrat du dépôt
 * réel, en mémoire : `saveDiscovered()` ne touche jamais un client
 * pré-enregistré (il rend `null`) et rafraîchit un client déjà déclaré,
 * `isDiscovered()` n'est vrai que pour un client déclaré ; `discoveryChecks`
 * compte les lectures de `isDiscovered()`.
 */
final class McpClientRepositoryFake implements McpClientRepositoryInterface
{
    /** @var list<McpClient> */
    public array $added = [];

    /** @var array<string, McpClient> les clients déclarés par document, par `client_id` */
    public array $discovered = [];

    /** Le nombre de lectures de `isDiscovered()`. */
    public int $discoveryChecks = 0;

    public function __construct(
        private readonly ?McpClient $existing,
        private readonly ?Throwable $failure,
    ) {
    }

    public function findOneByClientId(string $clientId): ?McpClient
    {
        return $this->existing;
    }

    public function add(McpClient $client): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->added[] = $client;
    }

    public function saveDiscovered(McpClient $discovered): ?McpClient
    {
        $clientId = $discovered->getClientId();

        if (null !== $this->existing && $clientId === $this->existing->getClientId()
            && ClientRegistration::Preregistered === $this->existing->registration()) {
            return null;
        }

        return $this->discovered[$clientId] = $discovered;
    }

    public function isDiscovered(McpClientId $clientId): bool
    {
        ++$this->discoveryChecks;

        return isset($this->discovered[(string) $clientId])
            || (null !== $this->existing && (string) $clientId === $this->existing->getClientId()
                && ClientRegistration::Cimd === $this->existing->registration());
    }
}
