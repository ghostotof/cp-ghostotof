<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Support;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;

/**
 * Provider de test qui rend une valeur brute, quelle qu'elle soit : ce que
 * rendrait un provider remplacé ou décoré sans que le renderer le sache (un
 * objet seul, `null`, une collection d'un autre DTO). StubProvider, typé sur
 * une liste de T, ne sait pas fabriquer ces cas.
 *
 * @template T of object
 *
 * @implements ProviderInterface<T>
 */
final readonly class RawResultProvider implements ProviderInterface
{
    public function __construct(private mixed $result)
    {
    }

    /**
     * @return T|iterable<T>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        /** @var T|list<T>|null $result le mensonge de type est l'objet du test */
        $result = $this->result;

        return $result;
    }
}
