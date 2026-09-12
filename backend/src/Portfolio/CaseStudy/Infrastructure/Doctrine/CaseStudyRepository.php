<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Infrastructure\Doctrine;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CaseStudy>
 */
class CaseStudyRepository extends ServiceEntityRepository implements CaseStudyRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CaseStudy::class);
    }

    public function findOneById(int $id): ?CaseStudy
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('caseStudy')
            ->andWhere('caseStudy.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('caseStudy.position', 'ASC')
            ->addOrderBy('caseStudy.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('caseStudy')
            ->orderBy('caseStudy.locale', 'ASC')
            ->addOrderBy('caseStudy.position', 'ASC')
            ->addOrderBy('caseStudy.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(CaseStudy $caseStudy): void
    {
        $this->getEntityManager()->persist($caseStudy);
        $this->getEntityManager()->flush();
    }

    public function remove(CaseStudy $caseStudy): void
    {
        $this->getEntityManager()->remove($caseStudy);
        $this->getEntityManager()->flush();
    }
}
