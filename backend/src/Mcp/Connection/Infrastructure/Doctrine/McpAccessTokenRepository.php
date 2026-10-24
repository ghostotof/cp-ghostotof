<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpAccessToken>
 */
class McpAccessTokenRepository extends ServiceEntityRepository implements McpAccessTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpAccessToken::class);
    }

    public function add(McpAccessToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function findOneByHash(string $tokenHash): ?McpAccessToken
    {
        /** @var McpAccessToken|null */
        return $this->createQueryBuilder('token')
            ->addSelect('connection')
            ->innerJoin('token.connection', 'connection')
            ->where('token.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `UPDATE … WHERE revoked_at IS NULL` : la base décide, en une écriture,
     * et un jeton déjà révoqué garde son instant. L'instant est typé comme sa
     * colonne, avec fuseau (#434).
     */
    public function revokeByHash(string $tokenHash, DateTimeImmutable $at): void
    {
        $this->createQueryBuilder('token')
            ->update()
            ->set('token.revokedAt', ':at')
            ->where('token.tokenHash = :tokenHash')
            ->andWhere('token.revokedAt IS NULL')
            ->setParameter('at', $at, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->execute();
    }
}
