<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityTraitProcessor;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityTraitProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur les traits de qualité. DTO délibérément
 * séparé de QualityTraitResource (imbriqué dans le contrat public en lecture
 * seule QualityContentResource) : celui-ci expose l'id et la locale, plus
 * adapté à un formulaire d'édition. La locale est filtrée en query string
 * sur la collection (?locale=fr), jamais en paramètre de chemin (qui reste
 * réservé à l'id).
 */
#[ApiResource(
    shortName: 'BackofficeQualityTrait',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/quality/traits',
            provider: BackofficeQualityTraitProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/quality/traits/{id}',
            provider: BackofficeQualityTraitProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/quality/traits',
            processor: BackofficeQualityTraitProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/quality/traits/{id}',
            provider: BackofficeQualityTraitProvider::class,
            processor: BackofficeQualityTraitProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/quality/traits/{id}',
            provider: BackofficeQualityTraitProvider::class,
            processor: BackofficeQualityTraitProcessor::class,
        ),
    ],
)]
final class BackofficeQualityTraitResource
{
    public function __construct(
        public ?int $id = null,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['fr', 'en'])]
        public ?string $locale = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $label = '',
        public int $position = 0,
    ) {
    }
}
