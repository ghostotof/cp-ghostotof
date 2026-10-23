<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpAuthorizationCode>
 */
class McpAuthorizationCodeRepository extends ServiceEntityRepository implements McpAuthorizationCodeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpAuthorizationCode::class);
    }

    public function add(McpAuthorizationCode $code): void
    {
        $this->getEntityManager()->persist($code);
        $this->getEntityManager()->flush();
    }

    public function findOneByHash(string $codeHash): ?McpAuthorizationCode
    {
        /** @var McpAuthorizationCode|null */
        return $this->createQueryBuilder('code')
            ->addSelect('connection')
            ->innerJoin('code.connection', 'connection')
            ->where('code.codeHash = :codeHash')
            ->setParameter('codeHash', $codeHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `UPDATE … WHERE revoked_at IS NULL` : la base décide, en une écriture,
     * et un code déjà révoqué garde son instant. L'instant est typé comme sa
     * colonne, avec fuseau (#434).
     */
    public function revokeByHash(string $codeHash, DateTimeImmutable $at): void
    {
        $this->createQueryBuilder('code')
            ->update()
            ->set('code.revokedAt', ':at')
            ->where('code.codeHash = :codeHash')
            ->andWhere('code.revokedAt IS NULL')
            ->setParameter('at', $at, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('codeHash', $codeHash)
            ->getQuery()
            ->execute();
    }
}
