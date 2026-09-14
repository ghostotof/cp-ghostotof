<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Application;

use App\Portfolio\Watch\Application\WatchedProductAdministrator;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Exception\WatchedProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugAlreadyUsedException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugIsImmutableException;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WatchedProductAdministratorTest extends TestCase
{
    private WatchedProductRepositoryInterface&MockObject $repository;
    private WatchedProductAdministrator $administrator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(WatchedProductRepositoryInterface::class);
        $this->administrator = new WatchedProductAdministrator($this->repository, new OrderAssigner());
    }

    private function postgres(): WatchedProduct
    {
        return new WatchedProduct('postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4', 2);
    }

    public function testItRegistersANewProduct(): void
    {
        $this->repository->method('findOneBySlug')->willReturn(null);
        $this->repository->expects(self::once())->method('save');

        $product = $this->administrator->create('postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4', 2);

        self::assertSame('postgresql', $product->getSlug());
        self::assertSame('18.4', $product->getVersion());
    }

    /**
     * Le slug identifie le produit chez le fournisseur : deux entrées pour le
     * même slug produiraient deux lignes identiques dans le radar, alimentées
     * par le même appel.
     */
    public function testItRefusesASlugAlreadyWatched(): void
    {
        $this->repository->method('findOneBySlug')->willReturn($this->postgres());
        $this->repository->expects(self::never())->method('save');

        $this->expectException(WatchedProductSlugAlreadyUsedException::class);

        $this->administrator->create('postgresql', 'Doublon', VersionSource::MANUAL, '18.4', 3);
    }

    public function testItAppliesTheChangesOfAnExistingProduct(): void
    {
        $product = $this->postgres();
        $this->repository->method('findOneById')->willReturn($product);
        $this->repository->expects(self::once())->method('save');

        $updated = $this->administrator->update($product->getId(), 'postgresql', 'PostgreSQL 18', VersionSource::MANUAL, '18.6', 4);

        self::assertSame('PostgreSQL 18', $updated->getLabel());
        self::assertSame('18.6', $updated->getVersion());
        self::assertSame(4, $updated->getPosition());
    }

    /**
     * Suivre un autre produit, c'est créer une autre entrée : le slug construit
     * l'URL interrogée et l'entité ne l'expose pas en écriture. Le refus est
     * explicite plutôt que silencieux — laisser passer un slug modifié qui
     * n'est pas appliqué ferait croire à l'auteur que son changement a pris.
     */
    public function testItRefusesToChangeTheSlugOfAnExistingProduct(): void
    {
        $product = $this->postgres();
        $this->repository->method('findOneById')->willReturn($product);
        $this->repository->expects(self::never())->method('save');

        $this->expectException(WatchedProductSlugIsImmutableException::class);

        $this->administrator->update($product->getId(), 'mariadb', 'MariaDB', VersionSource::MANUAL, '11.4', 2);
    }

    public function testUpdatingAnUnknownProductIsReported(): void
    {
        $this->repository->method('findOneById')->willReturn(null);
        $this->repository->expects(self::never())->method('save');

        $this->expectException(WatchedProductNotFoundException::class);

        $this->administrator->update(Uuid::v7(), 'postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4', 0);
    }

    public function testItRemovesAnExistingProduct(): void
    {
        $product = $this->postgres();
        $this->repository->method('findOneById')->willReturn($product);
        $this->repository->expects(self::once())->method('remove')->with($product);

        $this->administrator->delete($product->getId());
    }

    public function testDeletingAnUnknownProductIsReported(): void
    {
        $this->repository->method('findOneById')->willReturn(null);
        $this->repository->expects(self::never())->method('remove');

        $this->expectException(WatchedProductNotFoundException::class);

        $this->administrator->delete(Uuid::v7());
    }

    /**
     * Spec 0004 D5/D6 : `WatchedProduct` n'a pas de groupe de traduction, son
     * `orderingKey()` est son id — le périmètre chargé est tout le catalogue
     * (`findAllOrdered()`), et la persistance passe par `saveAll()` (un seul
     * appel), jamais par `save()` (un flush par entité).
     */
    public function testReorderLoadsTheCatalogueAndSavesItInOneCall(): void
    {
        $php = new WatchedProduct('php', 'PHP', VersionSource::MANUAL, '8.4', 0);
        $postgres = $this->postgres();

        $this->repository->method('findAllOrdered')->willReturn([$php, $postgres]);
        $this->repository->expects(self::never())->method('save');
        $this->repository->expects(self::once())->method('saveAll')->with([$php, $postgres]);

        $this->administrator->reorder([$postgres->getId()->toRfc4122(), $php->getId()->toRfc4122()]);

        self::assertSame(1, $php->getPosition());
        self::assertSame(0, $postgres->getPosition());
    }
}
