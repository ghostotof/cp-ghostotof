<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Portfolio\About\Infrastructure\ApiPlatform\AboutContentProvider;

/**
 * Contenu de la page À propos, par locale. Public (cf. access_control dans
 * config/packages/security.yaml : aucune restriction sur ce chemin) : cette
 * donnée n'est pas personnelle identifiante (objectif n°9 du projet).
 *
 * Point d'audit C3 : le filtrage des cartes selon l'authentification a été
 * écarté, côté API comme côté frontend. L'intégralité du contenu — volets
 * "technical", "personal" ET "hobbies" — est délibérément publique ; c'est le
 * backoffice qui décide de ce qui est publié ici, il n'y a donc rien à masquer
 * a posteriori. Ne pas réintroduire de filtre conditionnel sans revoir cette
 * décision (cf. tasks/plan.md, phase 3).
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
