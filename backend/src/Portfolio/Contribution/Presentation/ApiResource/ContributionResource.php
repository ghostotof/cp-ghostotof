<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Portfolio\Contribution\Infrastructure\ApiPlatform\ContributionProvider;

/**
 * Contributions techniques publiques, par locale. Public (cf. access_control
 * dans config/packages/security.yaml : aucune restriction sur ce chemin) —
 * c'est précisément le contenu destiné à un visiteur non authentifié arrivé
 * depuis LinkedIn, et il ne porte aucune donnée personnelle identifiante
 * (objectif n°9 du projet).
 */
#[ApiResource(
    shortName: 'Contribution',
    operations: [
        new GetCollection(
            uriTemplate: '/contributions/{locale}',
            provider: ContributionProvider::class,
        ),
    ],
)]
final readonly class ContributionResource
{
    public function __construct(
        public string $title,
        public string $project,
        public string $reference,
        public string $url,
        public string $summary,
        public string $body,
    ) {
    }
}
