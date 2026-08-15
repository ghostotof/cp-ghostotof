<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\Doctrine;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QualityTraitEntity>
 */
class QualityTraitRepository extends ServiceEntityRepository implements QualityTraitRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QualityTraitEntity::class);
    }

    public function findOneById(int $id): ?QualityTraitEntity
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('trait')
            ->andWhere('trait.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('trait.position', 'ASC')
            ->addOrderBy('trait.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('trait')
            ->orderBy('trait.locale', 'ASC')
            ->addOrderBy('trait.position', 'ASC')
            ->addOrderBy('trait.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(QualityTraitEntity $trait): void
    {
        $this->getEntityManager()->persist($trait);
        $this->getEntityManager()->flush();
    }

    public function remove(QualityTraitEntity $trait): void
    {
        $this->getEntityManager()->remove($trait);
        $this->getEntityManager()->flush();
    }
}
