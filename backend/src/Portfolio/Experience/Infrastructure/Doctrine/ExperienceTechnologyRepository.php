<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\Doctrine;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ExperienceTechnology>
 */
class ExperienceTechnologyRepository extends ServiceEntityRepository implements ExperienceTechnologyRepositoryInterface
{
    /**
     * Contrainte CHECK qui borne `years` en base (issue #372), posée par
     * Version20261006120000 — qui l'écrit en dur, une migration étant un
     * historique figé. Nommée ici pour que le code et les tests n'en
     * recopient pas le nom.
     */
    public const string YEARS_CHECK_CONSTRAINT = 'chk_experience_technology_years';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExperienceTechnology::class);
    }

    public function findOneByName(string $name): ?ExperienceTechnology
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findOneById(Uuid $id): ?ExperienceTechnology
    {
        return $this->find($id);
    }

    public function save(ExperienceTechnology $technology): void
    {
        $this->getEntityManager()->persist($technology);
        $this->getEntityManager()->flush();
    }

    public function remove(ExperienceTechnology $technology): void
    {
        $this->getEntityManager()->remove($technology);
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
