<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\BackofficeCaseStudyProcessor;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\BackofficeCaseStudyProvider;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml). DTO délibérément séparé de
 * CaseStudyResource (contrat public en lecture seule) : celui-ci expose l'id
 * et la locale, plus adapté à un formulaire d'édition. Même forme que
 * BackofficeContributionResource.
 */
#[ApiResource(
    shortName: 'BackofficeCaseStudy',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/case-studies',
            provider: BackofficeCaseStudyProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/case-studies/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeCaseStudyProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/case-studies',
            processor: BackofficeCaseStudyProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/case-studies/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeCaseStudyProvider::class,
            processor: BackofficeCaseStudyProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/case-studies/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeCaseStudyProvider::class,
            processor: BackofficeCaseStudyProcessor::class,
        ),
    ],
)]
final class BackofficeCaseStudyResource
{
    public function __construct(
        public ?string $id = null,
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [Locale::class, 'values'])]
        public ?string $locale = null,
        /**
         * Spec 0004 D1 : groupe de traduction, en RFC 4122 — les entrées qui le
         * partagent sont le même contenu dans des langues différentes. Exposé
         * en lecture dès maintenant ; le Processor l'ignore encore, le côté
         * écriture (et sa contrainte de validation) arrive en B2.
         */
        public ?string $translationGroup = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title = '',
        #[Assert\NotBlank]
        public string $problem = '',
        #[Assert\NotBlank]
        public string $solution = '',
        #[Assert\NotBlank]
        public string $tradeoffs = '',
        #[Assert\NotBlank]
        public string $measuredResult = '',
        #[Assert\PositiveOrZero]
        public int $position = 0,
    ) {
    }

    /**
     * Fabrique unique du DTO : le Provider et le Processor la partagent, même
     * discipline que BackofficeContributionResource::fromEntity() (issue #15).
     */
    public static function fromEntity(CaseStudy $caseStudy): self
    {
        return new self(
            id: $caseStudy->getId()->toRfc4122(),
            locale: $caseStudy->getLocale()->value,
            translationGroup: $caseStudy->getTranslationGroup()->toRfc4122(),
            title: $caseStudy->getTitle(),
            problem: $caseStudy->getProblem(),
            solution: $caseStudy->getSolution(),
            tradeoffs: $caseStudy->getTradeoffs(),
            measuredResult: $caseStudy->getMeasuredResult(),
            position: $caseStudy->getPosition(),
        );
    }
}
