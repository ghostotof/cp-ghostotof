<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityPrincipleProcessor;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityPrincipleProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur les principes de qualité. DTO
 * délibérément séparé de QualityPrincipleResource (imbriqué dans le contrat
 * public en lecture seule QualityContentResource) : celui-ci expose l'id et
 * la locale, plus adapté à un formulaire d'édition. La locale est filtrée en
 * query string sur la collection (?locale=fr), jamais en paramètre de chemin
 * (qui reste réservé à l'id).
 */
#[ApiResource(
    shortName: 'BackofficeQualityPrinciple',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/quality/principles',
            provider: BackofficeQualityPrincipleProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/quality/principles/{id}',
            provider: BackofficeQualityPrincipleProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/quality/principles',
            processor: BackofficeQualityPrincipleProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/quality/principles/{id}',
            provider: BackofficeQualityPrincipleProvider::class,
            processor: BackofficeQualityPrincipleProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/quality/principles/{id}',
            provider: BackofficeQualityPrincipleProvider::class,
            processor: BackofficeQualityPrincipleProcessor::class,
        ),
    ],
)]
final class BackofficeQualityPrincipleResource
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
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        public string $iconKey = '',
        #[Assert\PositiveOrZero]
        public int $position = 0,
    ) {
    }
}
