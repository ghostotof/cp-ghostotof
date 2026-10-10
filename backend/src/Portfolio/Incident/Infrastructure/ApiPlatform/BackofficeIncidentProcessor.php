<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Incident\Presentation\ApiResource\BackofficeIncidentResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeIncidentResource, BackofficeIncidentResource|null>
 */
final readonly class BackofficeIncidentProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeIncidentResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private IncidentAdministratorInterface $incidentAdministrator,
    ) {
    }

    /**
     * @param BackofficeIncidentResource $data
     */
    private function create(mixed $data): BackofficeIncidentResource
    {
        // Le format est déjà borné en amont par #[Assert\Date] sur le DTO :
        // une valeur invalide n'atteint jamais ce point.
        $occurredAt = new \DateTimeImmutable($data->occurredAt);

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
            $this->translationGroup($data),
        );

        return BackofficeIncidentResource::fromEntity($incident);
    }

    /**
     * @param BackofficeIncidentResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeIncidentResource
    {
        // Le format est déjà borné en amont par #[Assert\Date] sur le DTO :
        // une valeur invalide n'atteint jamais ce point.
        $occurredAt = new \DateTimeImmutable($data->occurredAt);

        $incident = $this->incidentAdministrator->update(
            $id,
            $data->title,
            $data->version,
            $occurredAt,
            $data->impact,
            $data->rootCause,
            $data->resolution,
            $data->invariant,
            $this->translationGroup($data),
        );

        return BackofficeIncidentResource::fromEntity($incident);
    }

    private function delete(Uuid $id): void
    {
        $this->incidentAdministrator->delete($id);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeIncidentResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
