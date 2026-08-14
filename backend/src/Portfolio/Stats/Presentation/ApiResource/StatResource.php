<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Portfolio\Stats\Infrastructure\ApiPlatform\StatProvider;

/**
 * Statistiques affichées sur la landing page, par locale. Public (cf.
 * access_control dans config/packages/security.yaml : aucune restriction sur
 * ce chemin) : cette donnée n'est pas personnelle identifiante (objectif n°9
 * du projet).
 */
#[ApiResource(
    shortName: 'Stat',
    operations: [
        new GetCollection(
            uriTemplate: '/stats/{locale}',
            provider: StatProvider::class,
        ),
    ],
)]
final readonly class StatResource
{
    public function __construct(
        public string $value,
        public string $label,
        public string $iconKey,
    ) {
    }
}
