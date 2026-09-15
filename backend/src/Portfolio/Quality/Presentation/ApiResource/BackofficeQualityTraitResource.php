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
use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityTraitProcessor;
use App\Portfolio\Quality\Infrastructure\ApiPlatform\BackofficeQualityTraitProvider;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur les traits de qualité. DTO délibérément
 * séparé de QualityTraitResource (imbriqué dans le contrat public en lecture
 * seule QualityContentResource) : celui-ci expose l'id et la locale, plus
 * adapté à un formulaire d'édition. La locale est filtrée en query string
 * sur la collection (?locale=fr), jamais en paramètre de chemin (qui reste
 * réservé à l'id).
 */
#[ApiResource(
    shortName: 'BackofficeQualityTrait',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/quality/traits',
            provider: BackofficeQualityTraitProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/quality/traits/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeQualityTraitProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/quality/traits',
            processor: BackofficeQualityTraitProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/quality/traits/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeQualityTraitProvider::class,
            processor: BackofficeQualityTraitProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/quality/traits/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeQualityTraitProvider::class,
            processor: BackofficeQualityTraitProcessor::class,
        ),
    ],
)]
final class BackofficeQualityTraitResource
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
        #[Assert\Length(max: 180)]
        public string $label = '',
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
     *
     * L'entité est importée sous alias : `QualityTrait` seul prêterait à
     * confusion avec le mot-clé du langage.
     */
    public static function fromEntity(QualityTraitEntity $trait): self
    {
        return new self(
            id: $trait->getId()->toRfc4122(),
            locale: $trait->getLocale()->value,
            translationGroup: $trait->getTranslationGroup()->toRfc4122(),
            label: $trait->getLabel(),
            position: $trait->getPosition(),
        );
    }
}
