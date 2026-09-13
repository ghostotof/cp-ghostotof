<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\AnonymousCvProvider;

/**
 * CV sans identité, par locale. ADR 0003 D5 : contenu du palier de base —
 * publiable en soi (ni nom, ni employeur, ni coordonnées), mais pas anonyme :
 * voir l'access_control `^/api/anonymous-cv` (ROLE_USER,
 * config/packages/security.yaml). Un visiteur doit d'abord obtenir le jeton
 * du palier de base (POST /api/account/base-access, ADR 0003 D6).
 *
 * Le chemin est délibérément `/anonymous-cv` et non `/cv-anonymous` ou
 * `/cv/anonymous` : `^/api/cv` est réservé ROLE_TRUSTED (le vrai CV, D4) et
 * Symfony n'applique que la première règle d'access_control qui matche.
 */
#[ApiResource(
    shortName: 'AnonymousCvSection',
    operations: [
        new GetCollection(
            uriTemplate: '/anonymous-cv/{locale}',
            provider: AnonymousCvProvider::class,
        ),
    ],
)]
final readonly class AnonymousCvSectionResource
{
    public function __construct(
        public string $title,
        public string $skills,
        public int $yearsOfExperience,
        public string $achievements,
    ) {
    }
}
