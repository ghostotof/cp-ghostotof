<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Experience\Application\ExperienceTechnologyAdministratorInterface;
use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Portfolio\Experience\Presentation\ApiResource\BackofficeExperienceTechnologyResource;

/**
 * @implements ProcessorInterface<BackofficeExperienceTechnologyResource, BackofficeExperienceTechnologyResource|null>
 */
final readonly class BackofficeExperienceTechnologyProcessor implements ProcessorInterface
{
    public function __construct(
        private ExperienceTechnologyRegistrarInterface $experienceTechnologyRegistrar,
        private ExperienceTechnologyAdministratorInterface $experienceTechnologyAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeExperienceTechnologyResource
    {
        if ($operation instanceof Delete) {
            $this->experienceTechnologyAdministrator->delete((int) $uriVariables['id']);

            return null;
        }

        \assert($data instanceof BackofficeExperienceTechnologyResource);

        if ($operation instanceof Put) {
            $technology = $this->experienceTechnologyAdministrator->update(
                (int) $uriVariables['id'],
                $data->name,
                $data->years,
                $data->iconKey,
                $data->relatedTechnologyName,
            );
        } elseif ($operation instanceof Post) {
            $technology = $this->experienceTechnologyRegistrar->register(
                $data->name,
                $data->years,
                $data->iconKey,
                $data->relatedTechnologyName,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return new BackofficeExperienceTechnologyResource(
            id: $technology->getId(),
            name: $technology->getName(),
            years: $technology->getYears(),
            iconKey: $technology->getIconKey(),
            relatedTechnologyName: $technology->getRelatedTechnologyName(),
        );
    }
}
