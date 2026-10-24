<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpClient>
 */
class McpClientRepository extends ServiceEntityRepository implements McpClientRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpClient::class);
    }

    public function findOneByClientId(string $clientId): ?McpClient
    {
        /** @var McpClient|null */
        return $this->createQueryBuilder('client')
            ->where('client.clientId = :clientId')
            ->setParameter('clientId', $clientId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function add(McpClient $client): void
    {
        $this->getEntityManager()->persist($client);
        $this->getEntityManager()->flush();
    }
}
