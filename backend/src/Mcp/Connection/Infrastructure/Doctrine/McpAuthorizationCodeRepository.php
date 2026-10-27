<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<McpAuthorizationCode>
 */
class McpAuthorizationCodeRepository extends ServiceEntityRepository implements McpAuthorizationCodeRepositoryInterface
{
    use DeletesDeadTokens;

    public function __construct(
        ManagerRegistry $registry,
        private readonly RereadUnderLock $rereadUnderLock,
    ) {
        parent::__construct($registry, McpAuthorizationCode::class);
    }

    public function add(McpAuthorizationCode $code): void
    {
        $this->getEntityManager()->persist($code);
        $this->getEntityManager()->flush();
    }

    /**
     * Une lecture ordinaire, la connexion jointe : aucun `HINT_REFRESH` — la
     * relecture sous verrou n'est écrite qu'une fois dans le projet,
     * {@see self::rereadUnderConnectionLock()} (relecture du lot B de #649).
     */
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
     * La relecture sous verrou du projet ({@see RereadUnderLock::row()},
     * `.claude/rules/backend-architecture.md`), sans `FOR UPDATE` : le verrou
     * tenu est celui de la connexion (`lockForUpdate()`), et c'est lui qui
     * sérialise deux échanges d'un même code. `HINT_REFRESH` : l'échange
     * qu'une requête concurrente a validé pendant l'attente se voit ; sans
     * lui Doctrine rendrait l'instance déjà gérée sans la rafraîchir. La
     * connexion n'est pas jointe : elle vient d'être relue sous son verrou.
     */
    public function rereadUnderConnectionLock(Uuid $codeId): ?McpAuthorizationCode
    {
        return $this->rereadUnderLock->row($this->getEntityManager(), McpAuthorizationCode::class, $codeId, forUpdate: false);
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
