<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Portfolio\About\Infrastructure\ApiPlatform\AboutContentProvider;

/**
 * Contenu de la page À propos, par locale. Public (cf. access_control dans
 * config/packages/security.yaml : aucune restriction sur ce chemin) : cette
 * donnée n'est pas personnelle identifiante (objectif n°9 du projet). Le
 * filtrage des cartes "hobbies" selon l'authentification reste géré côté
 * frontend (frontend/src/presentation/pages/AboutPage.vue) — l'API renvoie
 * toujours le contenu complet.
 */
#[ApiResource(
    shortName: 'AboutContent',
    operations: [
        new Get(
            uriTemplate: '/about/{locale}',
            provider: AboutContentProvider::class,
        ),
    ],
)]
final readonly class AboutContentResource
{
    public function __construct(
        public AboutSiteSectionResource $site,
        public AboutMeSectionResource $me,
    ) {
    }
}
