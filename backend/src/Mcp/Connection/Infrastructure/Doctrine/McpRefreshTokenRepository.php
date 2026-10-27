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
    use DeletesDeadTokens;

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
     * Une lecture ordinaire, la connexion et le jeton d'accès joints : aucun
     * `HINT_REFRESH` — la relecture sous verrou n'est écrite qu'une fois dans
     * le projet, {@see self::rereadUnderConnectionLock()} (relecture du lot B
     * de #649). Le jeton d'accès est **chargé**, jamais un proxy (lot C) : le
     * point de révocation lit son empreinte avant d'ouvrir sa transaction, et
     * un proxy initialisé après que la purge a supprimé la ligne lève
     * `EntityNotFoundException` — un 500 — là où le lien, remis à NULL par la
     * cascade, se lit `null`. Chargé avec le jeton, il reste lisible quoi
     * qu'il advienne de sa ligne ensuite ; révoquer un jeton d'accès déjà
     * supprimé ne touche alors aucune ligne.
     */
    public function findOneByHash(string $tokenHash): ?McpRefreshToken
    {
        /** @var McpRefreshToken|null */
        return $this->createQueryBuilder('token')
            ->addSelect('connection', 'accessToken')
            ->innerJoin('token.connection', 'connection')
            ->leftJoin('token.accessToken', 'accessToken')
            ->where('token.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * La relecture sous verrou du projet ({@see RereadUnderLock::row()},
     * `.claude/rules/backend-architecture.md`), sans `FOR UPDATE` : le verrou
     * tenu est celui de la connexion (`lockForUpdate()`), et c'est lui qui
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
}
