<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\BackofficeAnonymousCvSectionProcessor;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\BackofficeAnonymousCvSectionProvider;
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
            provider: BackofficeAnonymousCvSectionProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/anonymous-cv',
            processor: BackofficeAnonymousCvSectionProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/anonymous-cv/{id}',
            provider: BackofficeAnonymousCvSectionProvider::class,
            processor: BackofficeAnonymousCvSectionProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/anonymous-cv/{id}',
            provider: BackofficeAnonymousCvSectionProvider::class,
            processor: BackofficeAnonymousCvSectionProcessor::class,
        ),
    ],
)]
final class BackofficeAnonymousCvSectionResource
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
        public string $skills = '',
        #[Assert\PositiveOrZero]
        public int $yearsOfExperience = 0,
        #[Assert\NotBlank]
        public string $achievements = '',
        #[Assert\PositiveOrZero]
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
            id: $section->getId(),
            locale: $section->getLocale()->value,
            title: $section->getTitle(),
            skills: $section->getSkills(),
            yearsOfExperience: $section->getYearsOfExperience(),
            achievements: $section->getAchievements(),
            position: $section->getPosition(),
        );
    }
}
