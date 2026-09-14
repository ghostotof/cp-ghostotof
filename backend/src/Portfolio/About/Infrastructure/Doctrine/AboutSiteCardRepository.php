<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\Doctrine;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AboutSiteCard>
 */
class AboutSiteCardRepository extends ServiceEntityRepository implements AboutSiteCardRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AboutSiteCard::class);
    }

    public function findOneById(Uuid $id): ?AboutSiteCard
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('card')
            ->andWhere('card.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('card.position', 'ASC')
            ->addOrderBy('card.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('card')
            ->orderBy('card.locale', 'ASC')
            ->addOrderBy('card.position', 'ASC')
            ->addOrderBy('card.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByTranslationGroup(Uuid $translationGroup): array
    {
        return $this->createQueryBuilder('card')
            ->andWhere('card.translationGroup = :translationGroup')
            ->setParameter('translationGroup', $translationGroup, UuidType::NAME)
            ->orderBy('card.locale', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(AboutSiteCard $card): void
    {
        $this->getEntityManager()->persist($card);
        $this->getEntityManager()->flush();
    }

    public function saveAll(array $cards): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($cards): void {
            foreach ($cards as $card) {
                $this->getEntityManager()->persist($card);
            }

            $this->getEntityManager()->flush();
        });
    }

    public function remove(AboutSiteCard $card): void
    {
        $this->getEntityManager()->remove($card);
        $this->getEntityManager()->flush();
    }
}
