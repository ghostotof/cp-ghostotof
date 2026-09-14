<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityPrincipleOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator. Les deux exceptions 422 remontent telles quelles, mappées
 * dans api_platform.yaml.
 *
 * @implements ProcessorInterface<BackofficeQualityPrincipleOrderResource, null>
 */
final readonly class BackofficeQualityPrincipleOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private QualityPrincipleAdministratorInterface $qualityPrincipleAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->qualityPrincipleAdministrator->reorder($data->keys());

        return null;
    }
}
