<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Experience\Application\ExperienceTechnologyAdministratorInterface;
use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Portfolio\Experience\Presentation\ApiResource\BackofficeExperienceTechnologyResource;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeExperienceTechnologyResource, BackofficeExperienceTechnologyResource|null>
 */
final readonly class BackofficeExperienceTechnologyProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeExperienceTechnologyResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private ExperienceTechnologyRegistrarInterface $experienceTechnologyRegistrar,
        private ExperienceTechnologyAdministratorInterface $experienceTechnologyAdministrator,
    ) {
    }

    /**
     * @param BackofficeExperienceTechnologyResource $data
     */
    private function create(mixed $data): BackofficeExperienceTechnologyResource
    {
        // Déjà validé par le Callback du DTO : une valeur hors bornes n'atteint pas ce point.
        $years = ExperienceYears::fromFloat($data->years);

        // Rogné et borné par le DTO (NotBlank normalisé, Length) : TechnologyName ne lève pas ici.
        $technology = $this->experienceTechnologyRegistrar->register(
            TechnologyName::fromString($data->name),
            $years,
            $data->iconKey,
            $data->relatedTechnologyName,
            $data->secondary,
        );

        return BackofficeExperienceTechnologyResource::fromEntity($technology);
    }

    /**
     * @param BackofficeExperienceTechnologyResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeExperienceTechnologyResource
    {
        // Déjà validé par le Callback du DTO : une valeur hors bornes n'atteint pas ce point.
        $years = ExperienceYears::fromFloat($data->years);

        $technology = $this->experienceTechnologyAdministrator->update(
            $id,
            TechnologyName::fromString($data->name),
            $years,
            $data->iconKey,
            $data->relatedTechnologyName,
            $data->secondary,
        );

        return BackofficeExperienceTechnologyResource::fromEntity($technology);
    }

    private function delete(Uuid $id): void
    {
        $this->experienceTechnologyAdministrator->delete($id);
    }
}
