<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\QualityContentProvider;

/**
 * Principes et traits de qualité mis en avant sur la landing page, par
 * locale. Public (cf. access_control dans config/packages/security.yaml :
 * aucune restriction sur ce chemin) : cette donnée n'est pas personnelle
 * identifiante (objectif n°9 du projet).
 */
#[ApiResource(
    shortName: 'QualityContent',
    operations: [
        new Get(
            uriTemplate: '/quality/{locale}',
            provider: QualityContentProvider::class,
        ),
    ],
)]
final readonly class QualityContentResource
{
    /**
     * @param list<QualityPrincipleResource> $principles
     * @param list<QualityTraitResource>     $traits
     */
    public function __construct(
        public array $principles,
        public array $traits,
    ) {
    }
}
