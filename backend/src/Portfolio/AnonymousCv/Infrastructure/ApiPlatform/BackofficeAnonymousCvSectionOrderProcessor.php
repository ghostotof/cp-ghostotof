<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\BackofficeAnonymousCvSectionOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator. Les deux exceptions 422 remontent telles quelles, mappées
 * dans api_platform.yaml.
 *
 * @implements ProcessorInterface<BackofficeAnonymousCvSectionOrderResource, null>
 */
final readonly class BackofficeAnonymousCvSectionOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private AnonymousCvSectionAdministratorInterface $anonymousCvSectionAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->anonymousCvSectionAdministrator->reorder($data->keys());

        return null;
    }
}
