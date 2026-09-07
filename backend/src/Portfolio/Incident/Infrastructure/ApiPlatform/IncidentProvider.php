<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Incident\Application\IncidentPresenterInterface;
use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Incident\Presentation\ApiResource\IncidentResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Relie IncidentResource (Presentation) au Domain : seule cette classe
 * Infrastructure connaît à la fois l'entité Doctrine et la ressource API
 * Platform.
 *
 * @implements ProviderInterface<IncidentResource>
 */
final readonly class IncidentProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private IncidentRepositoryInterface $incidentRepository,
        private IncidentPresenterInterface $incidentPresenter,
    ) {
    }

    /**
     * @return list<IncidentResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $locale = $this->uriVariableLocale($uriVariables);

        return array_map(
            fn (Incident $incident): IncidentResource => new IncidentResource(
                ...$this->incidentPresenter->present($incident),
            ),
            $this->incidentRepository->findByLocale($locale),
        );
    }
}
