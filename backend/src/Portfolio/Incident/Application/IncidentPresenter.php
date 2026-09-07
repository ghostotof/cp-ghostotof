<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Application;

use App\Portfolio\Incident\Domain\Entity\Incident;

final class IncidentPresenter implements IncidentPresenterInterface
{
    public function present(Incident $incident): array
    {
        return [
            'title' => $incident->getTitle(),
            'version' => $incident->getVersion(),
            // Format ISO plutôt qu'une date déjà mise en forme : le contrat
            // public reste neutre, la présentation localise elle-même.
            'occurredAt' => $incident->getOccurredAt()->format('Y-m-d'),
            'impact' => $incident->getImpact(),
            'rootCause' => $incident->getRootCause(),
            'resolution' => $incident->getResolution(),
            'invariant' => $incident->getInvariant(),
        ];
    }
}
