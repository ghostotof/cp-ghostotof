<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\BackofficeAnonymousCvSectionResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeAnonymousCvSectionResource, BackofficeAnonymousCvSectionResource|null>
 */
final readonly class BackofficeAnonymousCvSectionProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeAnonymousCvSectionResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private AnonymousCvSectionAdministratorInterface $sectionAdministrator,
    ) {
    }

    /**
     * @param BackofficeAnonymousCvSectionResource $data
     */
    private function create(mixed $data): BackofficeAnonymousCvSectionResource
    {
        // Locale::from (et non fromString) : la valeur est déjà bornée en
        // amont par #[Assert\Choice] sur le DTO. Un ValueError ici serait
        // un vrai défaut, et doit remonter en 500 plutôt que d'être
        // déguisé en 404.
        $section = $this->sectionAdministrator->create(
            Locale::from((string) $data->locale),
            $data->title,
            $data->skills,
            $data->yearsOfExperience,
            $data->achievements,
            $this->translationGroup($data),
        );

        return BackofficeAnonymousCvSectionResource::fromEntity($section);
    }

    /**
     * @param BackofficeAnonymousCvSectionResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeAnonymousCvSectionResource
    {
        $section = $this->sectionAdministrator->update(
            $id,
            $data->title,
            $data->skills,
            $data->yearsOfExperience,
            $data->achievements,
            $this->translationGroup($data),
        );

        return BackofficeAnonymousCvSectionResource::fromEntity($section);
    }

    private function delete(Uuid $id): void
    {
        $this->sectionAdministrator->delete($id);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeAnonymousCvSectionResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
