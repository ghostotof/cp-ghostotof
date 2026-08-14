<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use App\Portfolio\Experience\Presentation\ApiResource\BackofficeExperienceTechnologyResource;

/**
 * @implements ProviderInterface<BackofficeExperienceTechnologyResource>
 */
final readonly class BackofficeExperienceTechnologyProvider implements ProviderInterface
{
    public function __construct(
        private ExperienceTechnologyRepositoryInterface $experienceTechnologyRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeExperienceTechnologyResource|array|null
    {
        if ($operation instanceof GetCollection) {
            return array_map(
                $this->toResource(...),
                $this->experienceTechnologyRepository->findAllOrderedByYearsDesc(),
            );
        }

        $technology = $this->experienceTechnologyRepository->findOneById((int) $uriVariables['id']);

        return null !== $technology ? $this->toResource($technology) : null;
    }

    private function toResource(ExperienceTechnology $technology): BackofficeExperienceTechnologyResource
    {
        return new BackofficeExperienceTechnologyResource(
            id: $technology->getId(),
            name: $technology->getName(),
            years: $technology->getYears(),
            iconKey: $technology->getIconKey(),
            relatedTechnologyName: $technology->getRelatedTechnologyName(),
        );
    }
}
