<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Incident\Presentation\ApiResource\BackofficeIncidentOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator. Les deux exceptions 422 remontent telles quelles, mappées
 * dans api_platform.yaml.
 *
 * @implements ProcessorInterface<BackofficeIncidentOrderResource, null>
 */
final readonly class BackofficeIncidentOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private IncidentAdministratorInterface $incidentAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->incidentAdministrator->reorder($data->keys());

        return null;
    }
}
