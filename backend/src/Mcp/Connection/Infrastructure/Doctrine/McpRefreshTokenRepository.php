<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<McpRefreshToken>
 */
class McpRefreshTokenRepository extends ServiceEntityRepository implements McpRefreshTokenRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RereadUnderLock $rereadUnderLock,
    ) {
        parent::__construct($registry, McpRefreshToken::class);
    }

    public function add(McpRefreshToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    /**
     * Une lecture ordinaire, la connexion jointe : aucun `HINT_REFRESH` — la
     * relecture sous verrou n'est écrite qu'une fois dans le projet,
     * {@see self::rereadUnderConnectionLock()} (relecture du lot B de #649).
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
            ->getOneOrNullResult();
    }

    /**
     * La relecture sous verrou du projet ({@see RereadUnderLock::row()},
     * `.claude/rules/backend-architecture.md`), sans `FOR UPDATE` : le verrou
     * tenu est celui de la connexion (`lockForIssuance()`), et c'est lui qui
     * sérialise deux rafraîchissements d'un même jeton. `HINT_REFRESH` : la
     * rotation qu'une requête concurrente a validée pendant l'attente se voit
     * ; sans lui Doctrine rendrait l'instance déjà gérée sans la rafraîchir.
     * La connexion n'est pas jointe : elle vient d'être relue sous son verrou.
     */
    public function rereadUnderConnectionLock(Uuid $tokenId): ?McpRefreshToken
    {
        return $this->rereadUnderLock->row($this->getEntityManager(), McpRefreshToken::class, $tokenId, forUpdate: false);
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

    /**
     * Une instruction, jointe à la connexion pour lire sa révocation, par la
     * connexion DBAL : une suppression en masse, sans instance à charger.
     * L'instant est typé comme sa colonne, avec fuseau (#434). Un jeton tourné
     * non échu d'une connexion active n'est pas visé : sa ligne fait
     * reconnaître sa réutilisation. Aucun index sur `expires_at` : la table
     * reste bornée par cette purge quotidienne, à revoir si la volumétrie le
     * demande.
     */
    public function deleteDead(DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM mcp_refresh_token t USING mcp_connection c'
            .' WHERE c.id = t.connection_id AND (t.expires_at <= :now OR c.revoked_at IS NOT NULL)',
            ['now' => $now],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
