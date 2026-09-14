<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityPrincipleProcessor;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityPrincipleProvider;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Routing\Requirement\Requirement;
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
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeQualityPrincipleProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/quality/principles',
            processor: BackofficeQualityPrincipleProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/quality/principles/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeQualityPrincipleProvider::class,
            processor: BackofficeQualityPrincipleProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/quality/principles/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeQualityPrincipleProvider::class,
            processor: BackofficeQualityPrincipleProcessor::class,
        ),
    ],
)]
final class BackofficeQualityPrincipleResource
{
    public function __construct(
        public ?string $id = null,
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
        #[Assert\Length(max: 180)]
        public string $title = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public string $description = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        public string $iconKey = '',
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
    public static function fromEntity(QualityPrinciple $principle): self
    {
        return new self(
            id: $principle->getId()->toRfc4122(),
            locale: $principle->getLocale()->value,
            translationGroup: $principle->getTranslationGroup()->toRfc4122(),
            title: $principle->getTitle(),
            description: $principle->getDescription(),
            iconKey: $principle->getIconKey(),
            position: $principle->getPosition(),
        );
    }
}
