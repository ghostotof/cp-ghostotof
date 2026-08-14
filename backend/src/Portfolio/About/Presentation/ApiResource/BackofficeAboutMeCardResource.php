<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutMeCardProcessor;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutMeCardProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur les cartes "À propos de moi". DTO
 * délibérément séparé d'AboutCardResource (imbriqué dans le contrat public en
 * lecture seule AboutContentResource) : celui-ci expose l'id, la locale et la
 * catégorie (qui range la carte dans technicalCards/personalCards/hobbiesCards
 * côté public), plus adapté à un formulaire d'édition. Locale ET catégorie
 * sont filtrées en query string sur la collection (?locale=fr&category=technical),
 * jamais en paramètre de chemin (qui reste réservé à l'id).
 */
#[ApiResource(
    shortName: 'BackofficeAboutMeCard',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/about/me-cards',
            provider: BackofficeAboutMeCardProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/about/me-cards/{id}',
            provider: BackofficeAboutMeCardProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/about/me-cards',
            processor: BackofficeAboutMeCardProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/about/me-cards/{id}',
            provider: BackofficeAboutMeCardProvider::class,
            processor: BackofficeAboutMeCardProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/about/me-cards/{id}',
            provider: BackofficeAboutMeCardProvider::class,
            processor: BackofficeAboutMeCardProcessor::class,
        ),
    ],
)]
final class BackofficeAboutMeCardResource
{
    public function __construct(
        public ?int $id = null,
        #[Assert\Choice(choices: ['fr', 'en'])]
        public ?string $locale = null,
        #[Assert\Choice(choices: ['technical', 'personal', 'hobby'])]
        public ?string $category = null,
        #[Assert\NotBlank]
        public string $title = '',
        #[Assert\NotBlank]
        public string $description = '',
        public ?string $iconKey = null,
        public int $position = 0,
    ) {
    }
}
