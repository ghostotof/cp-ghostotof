<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\Doctrine;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QualityPrinciple>
 */
class QualityPrincipleRepository extends ServiceEntityRepository implements QualityPrincipleRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QualityPrinciple::class);
    }

    public function findOneById(int $id): ?QualityPrinciple
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('principle')
            ->andWhere('principle.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('principle.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('principle')
            ->orderBy('principle.locale', 'ASC')
            ->addOrderBy('principle.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(QualityPrinciple $principle): void
    {
        $this->getEntityManager()->persist($principle);
        $this->getEntityManager()->flush();
    }

    public function remove(QualityPrinciple $principle): void
    {
        $this->getEntityManager()->remove($principle);
        $this->getEntityManager()->flush();
    }
}
