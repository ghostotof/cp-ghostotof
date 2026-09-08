<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Infrastructure\ApiPlatform\BackofficeContributionProcessor;
use App\Portfolio\Contribution\Infrastructure\ApiPlatform\BackofficeContributionProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml). DTO délibérément séparé de
 * ContributionResource (contrat public en lecture seule) : celui-ci expose
 * l'id et la locale, plus adapté à un formulaire d'édition. La locale est
 * filtrée en query string sur la collection (?locale=fr), jamais en paramètre
 * de chemin — réservé à l'id, comme sur les autres ressources backoffice.
 */
#[ApiResource(
    shortName: 'BackofficeContribution',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/contributions',
            provider: BackofficeContributionProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/contributions/{id}',
            provider: BackofficeContributionProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/contributions',
            processor: BackofficeContributionProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/contributions/{id}',
            provider: BackofficeContributionProvider::class,
            processor: BackofficeContributionProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/contributions/{id}',
            provider: BackofficeContributionProvider::class,
            processor: BackofficeContributionProcessor::class,
        ),
    ],
)]
final class BackofficeContributionResource
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
        #[Assert\Length(max: 120)]
        public string $project = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        public string $reference = '',
        #[Assert\NotBlank]
        #[Assert\Url]
        #[Assert\Length(max: 500)]
        public string $url = '',
        #[Assert\NotBlank]
        public string $summary = '',
        #[Assert\NotBlank]
        public string $body = '',
        #[Assert\PositiveOrZero]
        public int $position = 0,
    ) {
    }

    /**
     * Fabrique unique du DTO : le Provider et le Processor la partagent, faute
     * de quoi un champ ajouté à l'entité devait être reporté aux deux endroits
     * — sans qu'aucun test ne le rappelle (issue #15).
     */
    public static function fromEntity(Contribution $contribution): self
    {
        return new self(
            id: $contribution->getId(),
            locale: $contribution->getLocale()->value,
            title: $contribution->getTitle(),
            project: $contribution->getProject(),
            reference: $contribution->getReference(),
            url: $contribution->getUrl(),
            summary: $contribution->getSummary(),
            body: $contribution->getBody(),
            position: $contribution->getPosition(),
        );
    }
}
