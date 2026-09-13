<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\CaseStudyProvider;

/**
 * Études de cas techniques, par locale. ADR 0003 D5 : contenu du palier de
 * base — publiable en soi (jamais de nom de client), mais pas anonyme : voir
 * l'access_control `^/api/case-studies` (ROLE_USER, config/packages/security.yaml).
 * Un visiteur doit d'abord obtenir le jeton du palier de base
 * (POST /api/account/base-access, ADR 0003 D6) pour y accéder.
 */
#[ApiResource(
    shortName: 'CaseStudy',
    operations: [
        new GetCollection(
            uriTemplate: '/case-studies/{locale}',
            provider: CaseStudyProvider::class,
        ),
    ],
)]
final readonly class CaseStudyResource
{
    public function __construct(
        public string $title,
        public string $problem,
        public string $solution,
        public string $tradeoffs,
        public string $measuredResult,
    ) {
    }
}
