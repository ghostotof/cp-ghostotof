<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\BackofficeAnonymousCvSectionProcessor;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\BackofficeAnonymousCvSectionProvider;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml). DTO délibérément séparé de
 * AnonymousCvSectionResource (contrat public en lecture seule) : celui-ci
 * expose l'id et la locale, plus adapté à un formulaire d'édition. Même forme
 * que BackofficeCaseStudyResource.
 */
#[ApiResource(
    shortName: 'BackofficeAnonymousCvSection',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/anonymous-cv',
            provider: BackofficeAnonymousCvSectionProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/anonymous-cv/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeAnonymousCvSectionProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/anonymous-cv',
            processor: BackofficeAnonymousCvSectionProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/anonymous-cv/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeAnonymousCvSectionProvider::class,
            processor: BackofficeAnonymousCvSectionProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/anonymous-cv/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeAnonymousCvSectionProvider::class,
            processor: BackofficeAnonymousCvSectionProcessor::class,
        ),
    ],
)]
final class BackofficeAnonymousCvSectionResource
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
        #[Assert\Length(max: 255)]
        public string $title = '',
        #[Assert\NotBlank]
        public string $skills = '',
        #[Assert\PositiveOrZero]
        public int $yearsOfExperience = 0,
        #[Assert\NotBlank]
        public string $achievements = '',
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
     * Fabrique unique du DTO : le Provider et le Processor la partagent, même
     * discipline que BackofficeCaseStudyResource::fromEntity().
     */
    public static function fromEntity(AnonymousCvSection $section): self
    {
        return new self(
            id: $section->getId()->toRfc4122(),
            locale: $section->getLocale()->value,
            translationGroup: $section->getTranslationGroup()->toRfc4122(),
            title: $section->getTitle(),
            skills: $section->getSkills(),
            yearsOfExperience: $section->getYearsOfExperience(),
            achievements: $section->getAchievements(),
            position: $section->getPosition(),
        );
    }
}
