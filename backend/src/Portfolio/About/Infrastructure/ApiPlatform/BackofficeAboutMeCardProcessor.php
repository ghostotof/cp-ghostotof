<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeAboutMeCardResource, BackofficeAboutMeCardResource|null>
 */
final readonly class BackofficeAboutMeCardProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeAboutMeCardResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private AboutMeCardAdministratorInterface $aboutMeCardAdministrator,
    ) {
    }

    /**
     * @param BackofficeAboutMeCardResource $data
     */
    private function create(mixed $data): BackofficeAboutMeCardResource
    {
        $card = $this->aboutMeCardAdministrator->create(
            Locale::from((string) $data->locale),
            $this->category($data),
            $data->title,
            $data->description,
            $data->iconKey,
            $this->translationGroup($data),
        );

        return BackofficeAboutMeCardResource::fromEntity($card);
    }

    /**
     * @param BackofficeAboutMeCardResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeAboutMeCardResource
    {
        // La catégorie est transmise pour que l'administrateur refuse une autre
        // catégorie que celle de la carte (issue #578), au lieu de l'ignorer.
        $card = $this->aboutMeCardAdministrator->update(
            $id,
            Locale::from((string) $data->locale),
            $this->category($data),
            $data->title,
            $data->description,
            $data->iconKey,
            $this->translationGroup($data),
        );

        return BackofficeAboutMeCardResource::fromEntity($card);
    }

    private function delete(Uuid $id): void
    {
        $this->aboutMeCardAdministrator->delete($id);
    }

    /**
     * `AboutMeCardCategory::from` : valeur déjà bornée par `#[Assert\Choice]`
     * en amont. Un `ValueError` ici serait un vrai défaut et doit remonter en 500.
     */
    private function category(BackofficeAboutMeCardResource $data): AboutMeCardCategory
    {
        return AboutMeCardCategory::from((string) $data->category);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeAboutMeCardResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
