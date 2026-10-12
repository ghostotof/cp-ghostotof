<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityPrincipleResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeQualityPrincipleResource, BackofficeQualityPrincipleResource|null>
 */
final readonly class BackofficeQualityPrincipleProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeQualityPrincipleResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private QualityPrincipleAdministratorInterface $qualityPrincipleAdministrator,
    ) {
    }

    /**
     * @param BackofficeQualityPrincipleResource $data
     */
    private function create(mixed $data): BackofficeQualityPrincipleResource
    {
        $principle = $this->qualityPrincipleAdministrator->create(
            Locale::from((string) $data->locale),
            $data->title,
            $data->description,
            $data->iconKey,
            $this->translationGroup($data),
        );

        return BackofficeQualityPrincipleResource::fromEntity($principle);
    }

    /**
     * @param BackofficeQualityPrincipleResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeQualityPrincipleResource
    {
        $principle = $this->qualityPrincipleAdministrator->update(
            $id,
            $data->title,
            $data->description,
            $data->iconKey,
            $this->translationGroup($data),
        );

        return BackofficeQualityPrincipleResource::fromEntity($principle);
    }

    private function delete(Uuid $id): void
    {
        $this->qualityPrincipleAdministrator->delete($id);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeQualityPrincipleResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
