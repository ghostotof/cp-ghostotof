<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Presentation\ApiResource\BackofficeWatchedProductResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Pas de filtre `?locale=` ici, contrairement aux autres collections du
 * backoffice : le catalogue des produits suivis n'est pas localisé (D6).
 *
 * @implements ProviderInterface<BackofficeWatchedProductResource>
 */
final readonly class BackofficeWatchedProductProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private WatchedProductRepositoryInterface $watchedProductRepository,
    ) {
    }

    /**
     * @return BackofficeWatchedProductResource|list<BackofficeWatchedProductResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeWatchedProductResource|array|null
    {
        if ($operation instanceof GetCollection) {
            return array_map($this->toResource(...), $this->watchedProductRepository->findAllOrdered());
        }

        $product = $this->watchedProductRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $product ? $this->toResource($product) : null;
    }

    private function toResource(WatchedProduct $product): BackofficeWatchedProductResource
    {
        return new BackofficeWatchedProductResource(
            id: $product->getId(),
            slug: $product->getSlug(),
            label: $product->getLabel(),
            versionSource: $product->getVersionSource()->value,
            version: $product->getVersion(),
            position: $product->getPosition(),
        );
    }
}
