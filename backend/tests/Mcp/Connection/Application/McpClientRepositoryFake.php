<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use Symfony\Component\Uid\Uuid;
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
 *
 * Les cinq méthodes de l'administration (#652) travaillent en mémoire, sur
 * ce que le fake connaît (`existing`, `added`, `discovered`), sans notion de
 * verrou : `all()` trie comme le dépôt réel, la lecture simple par clé et les
 * deux lectures verrouillées sont de simples lectures, `remove()` retire le
 * client de ces trois sources et le garde dans `removed`.
 */
final class McpClientRepositoryFake implements McpClientRepositoryInterface
{
    /** @var list<McpClient> */
    public array $added = [];

    /** @var array<string, McpClient> les clients déclarés par document, par `client_id` */
    public array $discovered = [];

    /** Le nombre de lectures de `isDiscovered()`. */
    public int $discoveryChecks = 0;

    /** @var list<McpClient> les clients supprimés par `remove()`, dans l'ordre */
    public array $removed = [];

    public function __construct(
        private ?McpClient $existing,
        private readonly ?Throwable $failure,
    ) {
    }

    public function findOneByClientId(string $clientId): ?McpClient
    {
        return $this->existing;
    }

    public function registrationOf(string $clientId): ?ClientRegistration
    {
        return null !== $this->existing && $clientId === $this->existing->getClientId() ? $this->existing->registration() : null;
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

    /** @return list<McpClient> */
    public function all(): array
    {
        $clients = $this->known();
        // Même tri que le dépôt réel : la date décroissante, puis la clé décroissante.
        usort($clients, static fn (McpClient $a, McpClient $b): int => [$b->registeredAt(), $b->getId()->toRfc4122()] <=> [$a->registeredAt(), $a->getId()->toRfc4122()]);

        return $clients;
    }

    public function findOneById(Uuid $id): ?McpClient
    {
        foreach ($this->known() as $client) {
            if ($client->getId()->equals($id)) {
                return $client;
            }
        }

        return null;
    }

    public function findOneByIdForUpdate(Uuid $id): ?McpClient
    {
        return $this->findOneById($id);
    }

    public function findOneByClientIdForShare(string $clientId): ?McpClient
    {
        return $this->findOneByClientId($clientId) ?? $this->discovered[$clientId] ?? null;
    }

    public function remove(McpClient $client): void
    {
        $this->removed[] = $client;
        $this->added = array_values(array_filter($this->added, static fn (McpClient $known): bool => $known !== $client));
        unset($this->discovered[$client->getClientId()]);

        if ($this->existing === $client) {
            $this->existing = null;
        }
    }

    /**
     * Tout ce que le fake connaît, sans doublon (le même objet peut être à la
     * fois `existing` et `added`).
     *
     * @return list<McpClient>
     */
    private function known(): array
    {
        $known = [];
        foreach ([...($this->existing instanceof McpClient ? [$this->existing] : []), ...$this->added, ...array_values($this->discovered)] as $client) {
            $known[spl_object_id($client)] = $client;
        }

        return array_values($known);
    }
}
