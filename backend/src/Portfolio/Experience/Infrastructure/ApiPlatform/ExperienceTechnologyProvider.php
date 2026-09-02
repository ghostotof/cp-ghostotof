<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Experience\Application\ExperienceTechnologyPresenterInterface;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use App\Portfolio\Experience\Presentation\ApiResource\ExperienceRelatedTechnologyResource;
use App\Portfolio\Experience\Presentation\ApiResource\ExperienceTechnologyResource;

/**
 * Relie ExperienceTechnologyResource (Presentation) au Domain : seule cette
 * classe Infrastructure a le droit de connaître à la fois l'entité Doctrine
 * (via le repository) et la ressource API Platform. L'Application
 * (ExperienceTechnologyPresenterInterface) reste framework-agnostique.
 *
 * @implements ProviderInterface<ExperienceTechnologyResource>
 */
final readonly class ExperienceTechnologyProvider implements ProviderInterface
{
    public function __construct(
        private ExperienceTechnologyRepositoryInterface $experienceTechnologyRepository,
        private ExperienceTechnologyPresenterInterface $experienceTechnologyPresenter,
    ) {
    }

    /** @return list<ExperienceTechnologyResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            function ($technology): ExperienceTechnologyResource {
                $presented = $this->experienceTechnologyPresenter->present($technology);

                return new ExperienceTechnologyResource(
                    name: $presented['name'],
                    years: $presented['years'],
                    iconKey: $presented['iconKey'],
                    relatedTechnology: null !== $presented['relatedTechnology']
                        ? new ExperienceRelatedTechnologyResource($presented['relatedTechnology']['name'])
                        : null,
                );
            },
            $this->experienceTechnologyRepository->findAllOrderedByYearsDesc(),
        );
    }
}
