<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\InvalidExperienceYearsException;
use App\Portfolio\Experience\Domain\Exception\InvalidTechnologyNameException;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Portfolio\Experience\Infrastructure\ApiPlatform\BackofficeExperienceTechnologyProcessor;
use App\Portfolio\Experience\Infrastructure\ApiPlatform\BackofficeExperienceTechnologyProvider;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * CRUD réservé ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) sur le classement des technologies. DTO
 * délibérément séparé d'ExperienceTechnologyResource (contrat public en
 * lecture seule, cf. /api/experience/technologies) : celui-ci expose l'id et
 * une forme à plat, plus adaptée à un formulaire d'édition.
 */
#[ApiResource(
    shortName: 'BackofficeExperienceTechnology',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/experience/technologies',
            provider: BackofficeExperienceTechnologyProvider::class,
        ),
        new Get(
            uriTemplate: '/backoffice/experience/technologies/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeExperienceTechnologyProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/experience/technologies',
            processor: BackofficeExperienceTechnologyProcessor::class,
        ),
        new Put(
            uriTemplate: '/backoffice/experience/technologies/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeExperienceTechnologyProvider::class,
            processor: BackofficeExperienceTechnologyProcessor::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/experience/technologies/{id}',
            requirements: ['id' => Requirement::UUID],
            provider: BackofficeExperienceTechnologyProvider::class,
            processor: BackofficeExperienceTechnologyProcessor::class,
        ),
    ],
)]
final class BackofficeExperienceTechnologyResource
{
    public function __construct(
        public ?string $id = null,
        /** Validé par validateName(), qui délègue à TechnologyName (issue #386). */
        public string $name = '',
        /** Validé par validateYears(), qui délègue à ExperienceYears (issue #372). */
        public float $years = 0.0,
        #[Assert\Length(max: 60)]
        public ?string $iconKey = null,
        #[Assert\Length(max: 180)]
        public ?string $relatedTechnologyName = null,
        /**
         * Sort la technologie du classement chiffré pour la ranger dans
         * l'énumération « également pratiquées », sans durée affichée.
         */
        public bool $secondary = false,
    ) {
    }

    /**
     * La règle du nom n'est écrite qu'une fois, dans TechnologyName (issue
     * #386) : la recopier en `NotBlank` et `Length` laissait passer un nom
     * fait d'espaces insécables ou porteur d'un octet NUL (500 `critical` à
     * l'INSERT). La violation reste attachée à `name`, avec le message du
     * domaine.
     */
    #[Assert\Callback]
    public function validateName(ExecutionContextInterface $context): void
    {
        try {
            TechnologyName::fromString($this->name);
        } catch (InvalidTechnologyNameException $exception) {
            $context->buildViolation($exception->getMessage())
                ->atPath('name')
                ->addViolation();
        }
    }

    /**
     * La règle de la durée n'est écrite qu'une fois, dans ExperienceYears : la
     * recopier en `PositiveOrZero` + `LessThanOrEqual` la dupliquait, et
     * rendait à l'admin le « This value should be… » générique au lieu du
     * message du domaine. La violation reste attachée à `years`, la forme que
     * les formulaires d'administration affichent déjà sous le champ.
     */
    #[Assert\Callback]
    public function validateYears(ExecutionContextInterface $context): void
    {
        try {
            ExperienceYears::fromFloat($this->years);
        } catch (InvalidExperienceYearsException $exception) {
            $context->buildViolation($exception->getMessage())
                ->atPath('years')
                ->addViolation();
        }
    }

    /**
     * Fabrique unique du DTO : le Provider et le Processor la partagent, faute
     * de quoi un champ ajouté à l'entité devait être reporté aux deux endroits
     * — sans qu'aucun test ne le rappelle (issue #15).
     */
    public static function fromEntity(ExperienceTechnology $technology): self
    {
        return new self(
            id: $technology->getId()->toRfc4122(),
            name: $technology->getName(),
            years: $technology->getYears(),
            iconKey: $technology->getIconKey(),
            relatedTechnologyName: $technology->getRelatedTechnologyName(),
            secondary: $technology->isSecondary(),
        );
    }
}
