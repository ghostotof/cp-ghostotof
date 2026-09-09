<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Exception\WatchedProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugAlreadyUsedException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugIsImmutableException;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;

/**
 * Administration du catalogue des produits suivis, depuis le backoffice.
 *
 * Les invariants de cohérence version/source restent portés par l'entité :
 * cette classe ne fait que garantir ce qu'elle seule peut voir, à savoir
 * l'unicité et l'immuabilité du slug — deux propriétés qui se constatent à
 * l'échelle du catalogue, pas d'une entrée isolée.
 */
final readonly class WatchedProductAdministrator implements WatchedProductAdministratorInterface
{
    public function __construct(
        private WatchedProductRepositoryInterface $watchedProductRepository,
    ) {
    }

    public function create(
        string $slug,
        string $label,
        VersionSource $versionSource,
        ?string $version,
        int $position,
    ): WatchedProduct {
        if (null !== $this->watchedProductRepository->findOneBySlug($slug)) {
            throw WatchedProductSlugAlreadyUsedException::forSlug($slug);
        }

        $product = new WatchedProduct($slug, $label, $versionSource, $version, $position);

        $this->watchedProductRepository->save($product);

        return $product;
    }

    public function update(
        int $id,
        string $slug,
        string $label,
        VersionSource $versionSource,
        ?string $version,
        int $position,
    ): WatchedProduct {
        $product = $this->watchedProductRepository->findOneById($id);

        if (null === $product) {
            throw WatchedProductNotFoundException::forId($id);
        }

        if ($product->getSlug() !== $slug) {
            throw WatchedProductSlugIsImmutableException::forSlugs($product->getSlug(), $slug);
        }

        $product->update($label, $versionSource, $version, $position);
        $this->watchedProductRepository->save($product);

        return $product;
    }

    public function delete(int $id): void
    {
        $product = $this->watchedProductRepository->findOneById($id);

        if (null === $product) {
            throw WatchedProductNotFoundException::forId($id);
        }

        $this->watchedProductRepository->remove($product);
    }
}
