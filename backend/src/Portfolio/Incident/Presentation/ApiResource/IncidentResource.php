<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Portfolio\Incident\Infrastructure\ApiPlatform\IncidentProvider;

/**
 * Incidents de production et invariants qui en sont sortis, par locale. Public
 * (cf. access_control dans config/packages/security.yaml : aucune restriction
 * sur ce chemin) — c'est du contenu de démonstration, sans donnée personnelle
 * identifiante (objectif n°9 du projet).
 */
#[ApiResource(
    shortName: 'Incident',
    operations: [
        new GetCollection(
            uriTemplate: '/incidents/{locale}',
            provider: IncidentProvider::class,
        ),
    ],
)]
final readonly class IncidentResource
{
    public function __construct(
        public string $title,
        public string $version,
        public string $occurredAt,
        public string $impact,
        public string $rootCause,
        public string $resolution,
        public string $invariant,
    ) {
    }
}
