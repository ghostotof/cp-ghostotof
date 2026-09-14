<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\CaseStudy\Application\CaseStudyAdministratorInterface;
use App\Portfolio\CaseStudy\Presentation\ApiResource\BackofficeCaseStudyOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator. Les deux exceptions 422 remontent telles quelles, mappées
 * dans api_platform.yaml.
 *
 * @implements ProcessorInterface<BackofficeCaseStudyOrderResource, null>
 */
final readonly class BackofficeCaseStudyOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private CaseStudyAdministratorInterface $caseStudyAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->caseStudyAdministrator->reorder($data->keys());

        return null;
    }
}
