<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Exception\WatchedProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugAlreadyUsedException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugIsImmutableException;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use Symfony\Component\Uid\Uuid;

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
        private OrderAssigner $orderAssigner,
    ) {
    }

    public function create(
        string $slug,
        string $label,
        VersionSource $versionSource,
        ?string $version,
    ): WatchedProduct {
        if (null !== $this->watchedProductRepository->findOneBySlug($slug)) {
            throw WatchedProductSlugAlreadyUsedException::forSlug($slug);
        }

        $product = new WatchedProduct($slug, $label, $versionSource, $version, $this->positionAtEnd());

        $this->watchedProductRepository->save($product);

        return $product;
    }

    public function update(
        Uuid $id,
        string $slug,
        string $label,
        VersionSource $versionSource,
        ?string $version,
    ): WatchedProduct {
        $product = $this->watchedProductRepository->findOneById($id);

        if (null === $product) {
            throw WatchedProductNotFoundException::forId($id);
        }

        if ($product->getSlug() !== $slug) {
            throw WatchedProductSlugIsImmutableException::forSlugs($product->getSlug(), $slug);
        }

        $product->update($label, $versionSource, $version);
        $this->watchedProductRepository->save($product);

        return $product;
    }

    public function delete(Uuid $id): void
    {
        $product = $this->watchedProductRepository->findOneById($id);

        if (null === $product) {
            throw WatchedProductNotFoundException::forId($id);
        }

        $this->watchedProductRepository->remove($product);
    }

    public function reorder(array $keys): void
    {
        $scope = $this->watchedProductRepository->findAllOrdered();

        $this->orderAssigner->assign($scope, $keys);

        $this->watchedProductRepository->saveAll($scope);
    }

    /**
     * Spec 0004 D3 : une entrée neuve se range après la dernière du catalogue,
     * 0 s'il est vide. Calculé ici et non par `ContentPlacement::atEndOf()`,
     * qui parle aux contenus traduisibles — `WatchedProduct` n'en est pas un.
     * Le catalogue est servi trié par position, la dernière entrée suffit.
     */
    private function positionAtEnd(): int
    {
        $catalogue = $this->watchedProductRepository->findAllOrdered();

        if ([] === $catalogue) {
            return 0;
        }

        return $catalogue[array_key_last($catalogue)]->getPosition() + 1;
    }
}
