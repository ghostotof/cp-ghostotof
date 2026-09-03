<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Experience\Infrastructure\ApiPlatform\BackofficeExperienceTechnologyProcessor;
use App\Portfolio\Experience\Infrastructure\ApiPlatform\BackofficeExperienceTechnologyProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur le classement des technologies. DTO
 * délibérément séparé d'ExperienceTechnologyResource (contrat public en
 * lecture seule, cf. /api/experience/technologies) : celui-ci expose l'id et
 * une forme à plat, plus adaptée à un formulaire d'édition.
 */
#[ApiResource(
    shortName: 'BackofficeExperienceTechnology',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/experience/technologies',
            provider: BackofficeExperienceTechnologyProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/experience/technologies/{id}',
            provider: BackofficeExperienceTechnologyProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/experience/technologies',
            processor: BackofficeExperienceTechnologyProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/experience/technologies/{id}',
            provider: BackofficeExperienceTechnologyProvider::class,
            processor: BackofficeExperienceTechnologyProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/experience/technologies/{id}',
            provider: BackofficeExperienceTechnologyProvider::class,
            processor: BackofficeExperienceTechnologyProcessor::class,
        ),
    ],
)]
final class BackofficeExperienceTechnologyResource
{
    public function __construct(
        public ?int $id = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $name = '',
        #[Assert\PositiveOrZero]
        public float $years = 0.0,
        #[Assert\Length(max: 60)]
        public ?string $iconKey = null,
        #[Assert\Length(max: 180)]
        public ?string $relatedTechnologyName = null,
    ) {
    }
}
