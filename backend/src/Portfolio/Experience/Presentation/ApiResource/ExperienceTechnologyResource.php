<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Portfolio\Experience\Infrastructure\ApiPlatform\ExperienceTechnologyProvider;

/**
 * Classement des technologies par temps cumulé, affiché sur la page
 * Expériences du frontend. Public (cf. access_control dans
 * config/packages/security.yaml : aucune restriction sur ce chemin) :
 * cette donnée n'est pas personnelle identifiante (objectif n°9 du projet).
 */
#[ApiResource(
    shortName: 'ExperienceTechnology',
    operations: [
        new GetCollection(
            uriTemplate: '/experience/technologies',
            provider: ExperienceTechnologyProvider::class,
        ),
    ],
)]
final readonly class ExperienceTechnologyResource
{
    public function __construct(
        public string $name,
        public float $years,
        public ?string $iconKey = null,
        public ?ExperienceRelatedTechnologyResource $relatedTechnology = null,
    ) {
    }
}
