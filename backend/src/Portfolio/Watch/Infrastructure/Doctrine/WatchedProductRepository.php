<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Doctrine;

use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WatchedProduct>
 */
class WatchedProductRepository extends ServiceEntityRepository implements WatchedProductRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchedProduct::class);
    }

    public function findOneById(Uuid $id): ?WatchedProduct
    {
        return $this->find($id);
    }

    public function findOneBySlug(string $slug): ?WatchedProduct
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('product')
            ->orderBy('product.position', 'ASC')
            ->addOrderBy('product.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(WatchedProduct $product): void
    {
        $this->getEntityManager()->persist($product);
        $this->getEntityManager()->flush();
    }

    public function remove(WatchedProduct $product): void
    {
        $this->getEntityManager()->remove($product);
        $this->getEntityManager()->flush();
    }
}
