<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Presentation\ApiResource\BackofficeContributionOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator. Les deux exceptions 422 remontent telles quelles, mappées
 * dans api_platform.yaml.
 *
 * @implements ProcessorInterface<BackofficeContributionOrderResource, null>
 */
final readonly class BackofficeContributionOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private ContributionAdministratorInterface $contributionAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->contributionAdministrator->reorder($data->keys());

        return null;
    }
}
