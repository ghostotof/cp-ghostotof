<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Doctrine;

use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WatchSnapshot>
 */
class WatchSnapshotRepository extends ServiceEntityRepository implements WatchSnapshotRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchSnapshot::class);
    }

    public function findOneByType(WatchSnapshotType $type): ?WatchSnapshot
    {
        return $this->findOneBy(['type' => $type]);
    }

    public function save(WatchSnapshot $snapshot): void
    {
        $this->getEntityManager()->persist($snapshot);
        $this->getEntityManager()->flush();
    }
}
