<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Presentation\ApiResource\BackofficeWatchedProductOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator. Les deux exceptions 422 remontent telles quelles, mappées
 * dans api_platform.yaml.
 *
 * @implements ProcessorInterface<BackofficeWatchedProductOrderResource, null>
 */
final readonly class BackofficeWatchedProductOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private WatchedProductAdministratorInterface $watchedProductAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->watchedProductAdministrator->reorder($data->keys());

        return null;
    }
}
