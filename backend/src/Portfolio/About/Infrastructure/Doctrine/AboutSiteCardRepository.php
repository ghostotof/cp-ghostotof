<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\Doctrine;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AboutSiteCard>
 */
class AboutSiteCardRepository extends ServiceEntityRepository implements AboutSiteCardRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AboutSiteCard::class);
    }

    public function findOneById(int $id): ?AboutSiteCard
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

    public function findAll(): array
    {
        return $this->createQueryBuilder('card')
            ->orderBy('card.locale', 'ASC')
            ->addOrderBy('card.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(AboutSiteCard $card): void
    {
        $this->getEntityManager()->persist($card);
        $this->getEntityManager()->flush();
    }

    public function remove(AboutSiteCard $card): void
    {
        $this->getEntityManager()->remove($card);
        $this->getEntityManager()->flush();
    }
}
