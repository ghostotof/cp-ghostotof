<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutSiteCardProcessor;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutSiteCardProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur les cartes "À propos de ce site". DTO
 * délibérément séparé d'AboutCardResource (imbriqué dans le contrat public en
 * lecture seule AboutContentResource) : celui-ci expose l'id et la locale,
 * plus adapté à un formulaire d'édition. La locale est filtrée en query
 * string sur la collection (?locale=fr), jamais en paramètre de chemin (qui
 * reste réservé à l'id).
 */
#[ApiResource(
    shortName: 'BackofficeAboutSiteCard',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/about/site-cards',
            provider: BackofficeAboutSiteCardProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/about/site-cards/{id}',
            provider: BackofficeAboutSiteCardProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/about/site-cards',
            processor: BackofficeAboutSiteCardProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/about/site-cards/{id}',
            provider: BackofficeAboutSiteCardProvider::class,
            processor: BackofficeAboutSiteCardProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/about/site-cards/{id}',
            provider: BackofficeAboutSiteCardProvider::class,
            processor: BackofficeAboutSiteCardProcessor::class,
        ),
    ],
)]
final class BackofficeAboutSiteCardResource
{
    public function __construct(
        public ?int $id = null,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['fr', 'en'])]
        public ?string $locale = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $title = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public string $description = '',
        #[Assert\Length(max: 60)]
        public ?string $iconKey = null,
        #[Assert\PositiveOrZero]
        public int $position = 0,
    ) {
    }
}
