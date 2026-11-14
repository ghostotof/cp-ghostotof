<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use App\Mcp\Connection\Application\Cimd\ClientMetadataStoreInterface;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;

/**
 * Doublure du magasin de documents (#651) : un tableau en mémoire, sans
 * horloge. Elle garde, pour chaque écriture, la durée demandée : c'est ce que
 * le résolveur décide (la durée), que les tests lisent, pas l'expiration.
 */
final class InMemoryClientMetadataStore implements ClientMetadataStoreInterface
{
    /**
     * @var array<string, string>
     */
    private array $bodies = [];

    /**
     * @var list<array{clientId: string, body: string, ttl: int}>
     */
    private array $saves = [];

    public function find(CimdClientId $clientId): ?string
    {
        return $this->bodies[$clientId->value()] ?? null;
    }

    public function save(CimdClientId $clientId, string $body, int $ttl): void
    {
        $this->bodies[$clientId->value()] = $body;
        $this->saves[] = ['clientId' => $clientId->value(), 'body' => $body, 'ttl' => $ttl];
    }

    /**
     * Pose un corps sans passer par `save()` : l'état d'un cache déjà rempli.
     */
    public function seed(CimdClientId $clientId, string $body): void
    {
        $this->bodies[$clientId->value()] = $body;
    }

    /**
     * @return list<array{clientId: string, body: string, ttl: int}>
     */
    public function saves(): array
    {
        return $this->saves;
    }
}
