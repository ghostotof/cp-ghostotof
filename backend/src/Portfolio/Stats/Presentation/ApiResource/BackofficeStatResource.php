<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Stats\Infrastructure\ApiPlatform\BackofficeStatProcessor;
use App\Portfolio\Stats\Infrastructure\ApiPlatform\BackofficeStatProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur les statistiques. DTO délibérément
 * séparé de StatResource (contrat public en lecture seule, cf.
 * /api/stats/{locale}) : celui-ci expose l'id et la locale, plus adapté à un
 * formulaire d'édition. La locale est filtrée en query string sur la
 * collection (?locale=fr), jamais en paramètre de chemin (qui reste réservé
 * à l'id, cf. décision d'architecture du plan backoffice).
 */
#[ApiResource(
    shortName: 'BackofficeStat',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/stats',
            provider: BackofficeStatProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/stats/{id}',
            provider: BackofficeStatProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/stats',
            processor: BackofficeStatProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/stats/{id}',
            provider: BackofficeStatProvider::class,
            processor: BackofficeStatProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/stats/{id}',
            provider: BackofficeStatProvider::class,
            processor: BackofficeStatProcessor::class,
        ),
    ],
)]
final class BackofficeStatResource
{
    public function __construct(
        public ?int $id = null,
        #[Assert\Choice(choices: ['fr', 'en'])]
        public ?string $locale = null,
        #[Assert\NotBlank]
        public string $value = '',
        #[Assert\NotBlank]
        public string $label = '',
        #[Assert\NotBlank]
        public string $iconKey = '',
        public int $position = 0,
    ) {
    }
}
