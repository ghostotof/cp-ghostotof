<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutMeCardProcessor;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutMeCardProvider;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur les cartes "À propos de moi". DTO
 * délibérément séparé d'AboutCardResource (imbriqué dans le contrat public en
 * lecture seule AboutContentResource) : celui-ci expose l'id, la locale et la
 * catégorie (qui range la carte dans technicalCards/personalCards/hobbiesCards
 * côté public), plus adapté à un formulaire d'édition. Locale ET catégorie
 * sont filtrées en query string sur la collection (?locale=fr&category=technical),
 * jamais en paramètre de chemin (qui reste réservé à l'id).
 */
#[ApiResource(
    shortName: 'BackofficeAboutMeCard',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/about/me-cards',
            provider: BackofficeAboutMeCardProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/about/me-cards/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeAboutMeCardProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/about/me-cards',
            processor: BackofficeAboutMeCardProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/about/me-cards/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeAboutMeCardProvider::class,
            processor: BackofficeAboutMeCardProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/about/me-cards/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeAboutMeCardProvider::class,
            processor: BackofficeAboutMeCardProcessor::class,
        ),
    ],
)]
final class BackofficeAboutMeCardResource
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
        #[Assert\Choice(choices: ['technical', 'personal', 'hobby'])]
        public ?string $category = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $title = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public string $description = '',
        #[Assert\Length(max: 60)]
        public ?string $iconKey = null,
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
    public static function fromEntity(AboutMeCard $card): self
    {
        return new self(
            id: $card->getId()->toRfc4122(),
            locale: $card->getLocale()->value,
            translationGroup: $card->getTranslationGroup()->toRfc4122(),
            category: $card->getCategory()->value,
            title: $card->getTitle(),
            description: $card->getDescription(),
            iconKey: $card->getIconKey(),
            position: $card->getPosition(),
        );
    }
}
