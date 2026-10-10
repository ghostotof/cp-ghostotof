<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSiteCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeAboutSiteCardResource, BackofficeAboutSiteCardResource|null>
 */
final readonly class BackofficeAboutSiteCardProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeAboutSiteCardResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private AboutSiteCardAdministratorInterface $aboutSiteCardAdministrator,
    ) {
    }

    /**
     * @param BackofficeAboutSiteCardResource $data
     */
    private function create(mixed $data): BackofficeAboutSiteCardResource
    {
        $card = $this->aboutSiteCardAdministrator->create(
            Locale::from((string) $data->locale),
            $data->title,
            $data->description,
            $data->iconKey,
            $this->translationGroup($data),
        );

        return BackofficeAboutSiteCardResource::fromEntity($card);
    }

    /**
     * @param BackofficeAboutSiteCardResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeAboutSiteCardResource
    {
        $card = $this->aboutSiteCardAdministrator->update(
            $id,
            $data->title,
            $data->description,
            $data->iconKey,
            $this->translationGroup($data),
        );

        return BackofficeAboutSiteCardResource::fromEntity($card);
    }

    private function delete(Uuid $id): void
    {
        $this->aboutSiteCardAdministrator->delete($id);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeAboutSiteCardResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
