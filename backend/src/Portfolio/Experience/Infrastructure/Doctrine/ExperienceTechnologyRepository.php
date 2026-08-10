<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\Doctrine;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExperienceTechnology>
 */
class ExperienceTechnologyRepository extends ServiceEntityRepository implements ExperienceTechnologyRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExperienceTechnology::class);
    }

    public function findOneByName(string $name): ?ExperienceTechnology
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function save(ExperienceTechnology $technology): void
    {
        $this->getEntityManager()->persist($technology);
        $this->getEntityManager()->flush();
    }

    public function findAllOrderedByYearsDesc(): array
    {
        return $this->createQueryBuilder('technology')
            ->orderBy('technology.years', 'DESC')
            ->addOrderBy('technology.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
