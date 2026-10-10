<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityTraitResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeQualityTraitResource, BackofficeQualityTraitResource|null>
 */
final readonly class BackofficeQualityTraitProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeQualityTraitResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private QualityTraitAdministratorInterface $qualityTraitAdministrator,
    ) {
    }

    /**
     * @param BackofficeQualityTraitResource $data
     */
    private function create(mixed $data): BackofficeQualityTraitResource
    {
        $trait = $this->qualityTraitAdministrator->create(
            Locale::from((string) $data->locale),
            $data->label,
            $this->translationGroup($data),
        );

        return BackofficeQualityTraitResource::fromEntity($trait);
    }

    /**
     * @param BackofficeQualityTraitResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeQualityTraitResource
    {
        $trait = $this->qualityTraitAdministrator->update(
            $id,
            $data->label,
            $this->translationGroup($data),
        );

        return BackofficeQualityTraitResource::fromEntity($trait);
    }

    private function delete(Uuid $id): void
    {
        $this->qualityTraitAdministrator->delete($id);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeQualityTraitResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
