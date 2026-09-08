<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Infrastructure\ApiPlatform\BackofficeIncidentProcessor;
use App\Portfolio\Incident\Infrastructure\ApiPlatform\BackofficeIncidentProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice). DTO séparé de
 * IncidentResource (contrat public en lecture seule) : celui-ci porte l'id, la
 * locale et la position, nécessaires au formulaire d'édition.
 *
 * `invariant` est NotBlank comme en base : le formulaire ne doit pas permettre
 * d'enregistrer un incident dont on n'a tiré aucune règle.
 */
#[ApiResource(
    shortName: 'BackofficeIncident',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/incidents',
            provider: BackofficeIncidentProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/incidents/{id}',
            provider: BackofficeIncidentProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/incidents',
            processor: BackofficeIncidentProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/incidents/{id}',
            provider: BackofficeIncidentProvider::class,
            processor: BackofficeIncidentProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/incidents/{id}',
            provider: BackofficeIncidentProvider::class,
            processor: BackofficeIncidentProcessor::class,
        ),
    ],
)]
final class BackofficeIncidentResource
{
    public function __construct(
        public ?int $id = null,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['fr', 'en'])]
        public ?string $locale = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        public string $version = '',
        #[Assert\NotBlank]
        #[Assert\Date(message: 'La date doit être au format AAAA-MM-JJ.')]
        public string $occurredAt = '',
        #[Assert\NotBlank]
        public string $impact = '',
        #[Assert\NotBlank]
        public string $rootCause = '',
        #[Assert\NotBlank]
        public string $resolution = '',
        #[Assert\NotBlank]
        public string $invariant = '',
        #[Assert\PositiveOrZero]
        public int $position = 0,
    ) {
    }

    /**
     * Fabrique unique du DTO : le Provider et le Processor la partagent, faute
     * de quoi un champ ajouté à l'entité devait être reporté aux deux endroits
     * — sans qu'aucun test ne le rappelle (issue #15).
     */
    public static function fromEntity(Incident $incident): self
    {
        return new self(
            id: $incident->getId(),
            locale: $incident->getLocale()->value,
            title: $incident->getTitle(),
            version: $incident->getVersion(),
            occurredAt: $incident->getOccurredAt()->format('Y-m-d'),
            impact: $incident->getImpact(),
            rootCause: $incident->getRootCause(),
            resolution: $incident->getResolution(),
            invariant: $incident->getInvariant(),
            position: $incident->getPosition(),
        );
    }
}
