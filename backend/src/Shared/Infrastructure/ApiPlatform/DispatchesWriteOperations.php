<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Symfony\Component\Uid\Uuid;

/**
 * Le `process()` commun aux Processors CRUD du backoffice (issue #338) : il
 * choisit l'action selon l'opération, et le Processor ne déclare que ses trois
 * actions. Chaque Processor recopiait ce choix, `else` compris.
 *
 * `Post` crée, `Put` met à jour l'entrée de l'URI, `Delete` la supprime et ne
 * rend rien. Toute autre opération est un défaut de câblage de la ressource,
 * jamais une requête cliente (le routeur ne publie que les opérations
 * déclarées) : UnsupportedOperationException, sans qu'aucune action ne parte.
 *
 * @template TResource of object
 */
trait DispatchesWriteOperations
{
    use ResolvesUriVariables;

    /**
     * @param TResource            $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return TResource|null
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        if ($operation instanceof Delete) {
            $this->delete($this->uriVariableUuid($uriVariables));

            return null;
        }

        if ($operation instanceof Put) {
            return $this->update($this->uriVariableUuid($uriVariables), $data);
        }

        if ($operation instanceof Post) {
            return $this->create($data);
        }

        throw UnsupportedOperationException::forOperation($operation);
    }

    /**
     * @param TResource $data
     *
     * @return TResource
     */
    abstract private function create(mixed $data): object;

    /**
     * @param TResource $data
     *
     * @return TResource
     */
    abstract private function update(Uuid $id, mixed $data): object;

    abstract private function delete(Uuid $id): void;
}
