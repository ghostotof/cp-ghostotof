<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Presentation\ApiResource\BackofficeWatchedProductResource;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeWatchedProductResource, BackofficeWatchedProductResource|null>
 */
final readonly class BackofficeWatchedProductProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeWatchedProductResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private WatchedProductAdministratorInterface $watchedProductAdministrator,
    ) {
    }

    /**
     * @param BackofficeWatchedProductResource $data
     */
    private function create(mixed $data): BackofficeWatchedProductResource
    {
        // VersionSource::from : valeur déjà bornée par #[Assert\Choice]. Un
        // ValueError ici serait un vrai défaut et doit remonter en 500.
        $versionSource = VersionSource::from($data->versionSource);
        $version = $this->normalizeVersion($data->version);

        $product = $this->watchedProductAdministrator->create(
            $data->slug,
            $data->label,
            $versionSource,
            $version,
        );

        return BackofficeWatchedProductResource::fromEntity($product);
    }

    /**
     * @param BackofficeWatchedProductResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeWatchedProductResource
    {
        // VersionSource::from : valeur déjà bornée par #[Assert\Choice]. Un
        // ValueError ici serait un vrai défaut et doit remonter en 500.
        $versionSource = VersionSource::from($data->versionSource);
        $version = $this->normalizeVersion($data->version);

        $product = $this->watchedProductAdministrator->update(
            $id,
            $data->slug,
            $data->label,
            $versionSource,
            $version,
        );

        return BackofficeWatchedProductResource::fromEntity($product);
    }

    private function delete(Uuid $id): void
    {
        $this->watchedProductAdministrator->delete($id);
    }

    /**
     * Un formulaire HTML envoie une chaîne vide pour un champ laissé libre, là
     * où le domaine attend `null`. Sans cette normalisation, choisir une source
     * runtime dans le formulaire produirait un 422 incompréhensible : « la
     * version ne peut pas être saisie » pour un champ que l'auteur a
     * précisément laissé vide.
     */
    private function normalizeVersion(?string $version): ?string
    {
        return null === $version || '' === trim($version) ? null : $version;
    }
}
