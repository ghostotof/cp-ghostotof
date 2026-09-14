<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\Doctrine;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<QualityTraitEntity>
 */
class QualityTraitRepository extends ServiceEntityRepository implements QualityTraitRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QualityTraitEntity::class);
    }

    public function findOneById(Uuid $id): ?QualityTraitEntity
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

    public function findByTranslationGroup(Uuid $translationGroup): array
    {
        return $this->createQueryBuilder('trait')
            ->andWhere('trait.translationGroup = :translationGroup')
            ->setParameter('translationGroup', $translationGroup, UuidType::NAME)
            ->orderBy('trait.locale', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(QualityTraitEntity $trait): void
    {
        $this->getEntityManager()->persist($trait);
        $this->getEntityManager()->flush();
    }

    public function saveAll(array $traits): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($traits): void {
            foreach ($traits as $trait) {
                $this->getEntityManager()->persist($trait);
            }

            $this->getEntityManager()->flush();
        });
    }

    public function remove(QualityTraitEntity $trait): void
    {
        $this->getEntityManager()->remove($trait);
        $this->getEntityManager()->flush();
    }
}
