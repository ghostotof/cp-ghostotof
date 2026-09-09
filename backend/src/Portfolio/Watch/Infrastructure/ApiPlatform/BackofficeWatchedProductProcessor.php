<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Presentation\ApiResource\BackofficeWatchedProductResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProcessorInterface<BackofficeWatchedProductResource, BackofficeWatchedProductResource|null>
 */
final readonly class BackofficeWatchedProductProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private WatchedProductAdministratorInterface $watchedProductAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeWatchedProductResource
    {
        if ($operation instanceof Delete) {
            $this->watchedProductAdministrator->delete($this->uriVariableInt($uriVariables, 'id'));

            return null;
        }

        // VersionSource::from : valeur déjà bornée par #[Assert\Choice]. Un
        // ValueError ici serait un vrai défaut et doit remonter en 500.
        $versionSource = VersionSource::from($data->versionSource);
        $version = $this->normalizeVersion($data->version);

        if ($operation instanceof Put) {
            $product = $this->watchedProductAdministrator->update(
                $this->uriVariableInt($uriVariables, 'id'),
                $data->slug,
                $data->label,
                $versionSource,
                $version,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            $product = $this->watchedProductAdministrator->create(
                $data->slug,
                $data->label,
                $versionSource,
                $version,
                $data->position,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return BackofficeWatchedProductResource::fromEntity($product);
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
