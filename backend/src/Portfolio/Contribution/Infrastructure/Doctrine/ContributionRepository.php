<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Infrastructure\Doctrine;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contribution>
 */
class ContributionRepository extends ServiceEntityRepository implements ContributionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contribution::class);
    }

    public function findOneById(int $id): ?Contribution
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('contribution')
            ->andWhere('contribution.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('contribution.position', 'ASC')
            ->addOrderBy('contribution.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('contribution')
            ->orderBy('contribution.locale', 'ASC')
            ->addOrderBy('contribution.position', 'ASC')
            ->addOrderBy('contribution.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Contribution $contribution): void
    {
        $this->getEntityManager()->persist($contribution);
        $this->getEntityManager()->flush();
    }

    public function remove(Contribution $contribution): void
    {
        $this->getEntityManager()->remove($contribution);
        $this->getEntityManager()->flush();
    }
}
