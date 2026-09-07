<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Application;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Domain\Exception\IncidentNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface IncidentAdministratorInterface
{
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
    ): Incident;

    /**
     * @throws IncidentNotFoundException si l'id est inconnu
     */
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
    ): Incident;

    /**
     * @throws IncidentNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
