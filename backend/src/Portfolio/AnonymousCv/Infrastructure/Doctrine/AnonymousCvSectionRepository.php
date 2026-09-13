<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Infrastructure\Doctrine;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnonymousCvSection>
 */
class AnonymousCvSectionRepository extends ServiceEntityRepository implements AnonymousCvSectionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnonymousCvSection::class);
    }

    public function findOneById(int $id): ?AnonymousCvSection
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('section')
            ->andWhere('section.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('section.position', 'ASC')
            ->addOrderBy('section.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('section')
            ->orderBy('section.locale', 'ASC')
            ->addOrderBy('section.position', 'ASC')
            ->addOrderBy('section.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(AnonymousCvSection $section): void
    {
        $this->getEntityManager()->persist($section);
        $this->getEntityManager()->flush();
    }

    public function remove(AnonymousCvSection $section): void
    {
        $this->getEntityManager()->remove($section);
        $this->getEntityManager()->flush();
    }
}
