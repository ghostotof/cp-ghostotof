<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSiteCardOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator. Les deux exceptions 422 remontent telles quelles, mappées
 * dans api_platform.yaml.
 *
 * @implements ProcessorInterface<BackofficeAboutSiteCardOrderResource, null>
 */
final readonly class BackofficeAboutSiteCardOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private AboutSiteCardAdministratorInterface $aboutSiteCardAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->aboutSiteCardAdministrator->reorder($data->keys());

        return null;
    }
}
