<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Infrastructure\ApiPlatform\BackofficeIncidentProcessor;
use App\Portfolio\Incident\Infrastructure\ApiPlatform\BackofficeIncidentProvider;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Routing\Requirement\Requirement;
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
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeIncidentProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/incidents',
            processor: BackofficeIncidentProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/incidents/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeIncidentProvider::class,
            processor: BackofficeIncidentProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/incidents/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeIncidentProvider::class,
            processor: BackofficeIncidentProcessor::class,
        ),
    ],
)]
final class BackofficeIncidentResource
{
    public function __construct(
        public ?string $id = null,
        /**
         * Lue à la création seulement. Le `PUT` l'ignore (#170, R2) : une
         * entrée ne change jamais de langue — elle appartient à un groupe de
         * traduction où sa langue est unique —, et le formulaire d'édition
         * désactive le champ. Toujours validée, pour qu'un corps incohérent
         * soit un 422 et non un silence.
         */
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [Locale::class, 'values'])]
        public ?string $locale = null,
        /**
         * Spec 0004 D1 : groupe de traduction, en RFC 4122 — les entrées qui le
         * partagent sont le même contenu dans des langues différentes.
         *
         * En écriture (D3) : à la création, le groupe de l'entrée dont celle-ci
         * est la traduction, ou `null` pour un contenu neuf ; sur un `PUT`,
         * `null` détache l'entrée de ses traductions.
         */
        #[Assert\Uuid]
        public ?string $translationGroup = null,
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
        /**
         * Spec 0004 D3 : lecture seule. La position ne se saisit plus — elle
         * se déduit du groupe ou de la fin du périmètre, et seul l'endpoint
         * d'ordre l'écrira. `writable: false` la retire du contrat d'écriture
         * (et de l'OpenAPI) sans pour autant refuser un corps qui en porterait
         * encore une : elle est simplement ignorée.
         */
        #[ApiProperty(writable: false)]
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
            id: $incident->getId()->toRfc4122(),
            locale: $incident->getLocale()->value,
            translationGroup: $incident->getTranslationGroup()->toRfc4122(),
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
