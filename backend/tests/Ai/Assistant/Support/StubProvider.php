<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Support;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;

/**
 * Provider de test : rend une liste préparée et retient les variables d'URI
 * reçues, pour vérifier que le renderer demande bien la locale attendue.
 *
 * @template T of object
 *
 * @implements ProviderInterface<T>
 */
final class StubProvider implements ProviderInterface
{
    /** @var array<string, mixed> */
    public array $lastUriVariables = [];

    /**
     * @param list<T> $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    /**
     * @return list<T>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->lastUriVariables = $uriVariables;

        return $this->entries;
    }
}
