<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpRefreshToken>
 */
class McpRefreshTokenRepository extends ServiceEntityRepository implements McpRefreshTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpRefreshToken::class);
    }

    public function add(McpRefreshToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    /**
     * Relu tel qu'en base (`HINT_REFRESH`), jamais tel que l'EntityManager le
     * tient (#649) : le point de jeton lit le jeton, verrouille sa connexion,
     * puis le relit sous le verrou — une rotation qu'une requête concurrente
     * a validée entre-temps doit se voir, et sans l'indice Doctrine rendrait
     * l'instance déjà gérée sans la rafraîchir. La connexion jointe est
     * rafraîchie de même.
     */
    public function findOneByHash(string $tokenHash): ?McpRefreshToken
    {
        /** @var McpRefreshToken|null */
        return $this->createQueryBuilder('token')
            ->addSelect('connection')
            ->innerJoin('token.connection', 'connection')
            ->where('token.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
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
