<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Infrastructure\Doctrine;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Incident>
 */
class IncidentRepository extends ServiceEntityRepository implements IncidentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    public function findOneById(int $id): ?Incident
    {
        return $this->find($id);
    }

    public function findByLocale(Locale $locale): array
    {
        return $this->createQueryBuilder('incident')
            ->andWhere('incident.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('incident.position', 'ASC')
            ->addOrderBy('incident.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAll(): array
    {
        return $this->createQueryBuilder('incident')
            ->orderBy('incident.locale', 'ASC')
            ->addOrderBy('incident.position', 'ASC')
            ->addOrderBy('incident.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Incident $incident): void
    {
        $this->getEntityManager()->persist($incident);
        $this->getEntityManager()->flush();
    }

    public function remove(Incident $incident): void
    {
        $this->getEntityManager()->remove($incident);
        $this->getEntityManager()->flush();
    }
}
