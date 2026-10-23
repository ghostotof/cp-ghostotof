<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<McpConnection>
 */
class McpConnectionRepository extends ServiceEntityRepository implements McpConnectionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpConnection::class);
    }

    /**
     * Compte et client chargés par jointures explicites : qui lit une
     * connexion lit presque toujours les deux (le consentement, la liste
     * « Mes connexions MCP »), une requête au lieu de trois.
     */
    public function findOneById(Uuid $id): ?McpConnection
    {
        /** @var McpConnection|null */
        return $this->createQueryBuilder('connection')
            ->addSelect('account', 'client')
            ->innerJoin('connection.account', 'account')
            ->innerJoin('connection.client', 'client')
            ->where('connection.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function add(McpConnection $connection): void
    {
        $this->getEntityManager()->persist($connection);
        $this->getEntityManager()->flush();
    }
}
