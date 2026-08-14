<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Infrastructure\Doctrine;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Domain\Entity\Stat;
use App\Portfolio\Stats\Domain\Repository\StatRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stat>
 */
class StatRepository extends ServiceEntityRepository implements StatRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stat::class);
    }

    public function findOneById(int $id): ?Stat
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('stat')
            ->andWhere('stat.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('stat.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('stat')
            ->orderBy('stat.locale', 'ASC')
            ->addOrderBy('stat.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Stat $stat): void
    {
        $this->getEntityManager()->persist($stat);
        $this->getEntityManager()->flush();
    }

    public function remove(Stat $stat): void
    {
        $this->getEntityManager()->remove($stat);
        $this->getEntityManager()->flush();
    }
}
