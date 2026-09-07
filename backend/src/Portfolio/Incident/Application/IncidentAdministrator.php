<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Application;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Domain\Exception\IncidentNotFoundException;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class IncidentAdministrator implements IncidentAdministratorInterface
{
    public function __construct(
        private IncidentRepositoryInterface $incidentRepository,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $version,
        \DateTimeImmutable $occurredAt,
        string $impact,
        string $rootCause,
        string $resolution,
        string $invariant,
        int $position,
    ): Incident {
        $incident = new Incident($locale, $title, $version, $occurredAt, $impact, $rootCause, $resolution, $invariant, $position);

        $this->incidentRepository->save($incident);

        return $incident;
    }

    public function update(
        int $id,
        string $title,
        string $version,
        \DateTimeImmutable $occurredAt,
        string $impact,
        string $rootCause,
        string $resolution,
        string $invariant,
        int $position,
    ): Incident {
        $incident = $this->incidentRepository->findOneById($id);

        if (null === $incident) {
            throw IncidentNotFoundException::forId($id);
        }

        $incident->update($title, $version, $occurredAt, $impact, $rootCause, $resolution, $invariant, $position);
        $this->incidentRepository->save($incident);

        return $incident;
    }

    public function delete(int $id): void
    {
        $incident = $this->incidentRepository->findOneById($id);

        if (null === $incident) {
            throw IncidentNotFoundException::forId($id);
        }

        $this->incidentRepository->remove($incident);
    }
}
