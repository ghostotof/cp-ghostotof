<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Application;

use App\Portfolio\Incident\Domain\Entity\Incident;

interface IncidentPresenterInterface
{
    /**
     * @return array{title: string, version: string, occurredAt: string, impact: string, rootCause: string, resolution: string, invariant: string}
     */
    public function present(Incident $incident): array;
}
