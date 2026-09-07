<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Incident\Presentation\ApiResource\BackofficeIncidentResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProcessorInterface<BackofficeIncidentResource, BackofficeIncidentResource|null>
 */
final readonly class BackofficeIncidentProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private IncidentAdministratorInterface $incidentAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeIncidentResource
    {
        if ($operation instanceof Delete) {
            $this->incidentAdministrator->delete($this->uriVariableInt($uriVariables, 'id'));

            return null;
        }

        // Le format est déjà borné en amont par #[Assert\Date] sur le DTO :
        // une valeur invalide n'atteint jamais ce point.
        $occurredAt = new \DateTimeImmutable($data->occurredAt);

        if ($operation instanceof Put) {
            $incident = $this->incidentAdministrator->update(
                $this->uriVariableInt($uriVariables, 'id'),
                $data->title,
                $data->version,
                $occurredAt,
                $data->impact,
                $data->rootCause,
                $data->resolution,
                $data->invariant,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            // Locale::from : valeur déjà bornée par #[Assert\Choice]. Un
            // ValueError ici serait un vrai défaut et doit remonter en 500.
            $incident = $this->incidentAdministrator->create(
                Locale::from((string) $data->locale),
                $data->title,
                $data->version,
                $occurredAt,
                $data->impact,
                $data->rootCause,
                $data->resolution,
                $data->invariant,
                $data->position,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return new BackofficeIncidentResource(
            id: $incident->getId(),
            locale: $incident->getLocale()->value,
            title: $incident->getTitle(),
            version: $incident->getVersion(),
            occurredAt: $incident->getOccurredAt()->format('Y-m-d'),
            impact: $incident->getImpact(),
            rootCause: $incident->getRootCause(),
            resolution: $incident->getResolution(),
            invariant: $incident->getInvariant(),
            position: $incident->getPosition(),
        );
    }
}
