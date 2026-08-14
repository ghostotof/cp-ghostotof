<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\Doctrine;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AboutMeCard>
 */
class AboutMeCardRepository extends ServiceEntityRepository implements AboutMeCardRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AboutMeCard::class);
    }

    public function findOneById(int $id): ?AboutMeCard
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('card')
            ->andWhere('card.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('card.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByLocaleAndCategory(Locale $locale, AboutMeCardCategory $category): array
    {
        return $this->createQueryBuilder('card')
            ->andWhere('card.locale = :locale')
            ->andWhere('card.category = :category')
            ->setParameter('locale', $locale)
            ->setParameter('category', $category)
            ->orderBy('card.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('card')
            ->orderBy('card.locale', 'ASC')
            ->addOrderBy('card.category', 'ASC')
            ->addOrderBy('card.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(AboutMeCard $card): void
    {
        $this->getEntityManager()->persist($card);
        $this->getEntityManager()->flush();
    }

    public function remove(AboutMeCard $card): void
    {
        $this->getEntityManager()->remove($card);
        $this->getEntityManager()->flush();
    }
}
