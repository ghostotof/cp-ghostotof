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

    /**
     * Une requête, jointures explicites et entités hydratées (ADR 0006 D4) :
     * l'authentificateur de `/api/mcp` lit à chaque appel la connexion, les
     * rôles et l'UUID du compte, l'identifiant du client. Sans `addSelect`, le
     * compte et le client resteraient des objets paresseux, chacun chargé par
     * sa propre requête au premier accès — trois requêtes au lieu d'une
     * (`McpAccessTokenRepositoryTest`). Les jointures sont internes : les trois
     * clés étrangères sont non nulles, en cascade.
     */
    public function findOneByHash(string $tokenHash): ?McpAccessToken
    {
        /** @var McpAccessToken|null */
        return $this->createQueryBuilder('token')
            ->addSelect('connection', 'account', 'client')
            ->innerJoin('token.connection', 'connection')
            ->innerJoin('connection.account', 'account')
            ->innerJoin('connection.client', 'client')
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

    /**
     * Une instruction, jointe à la connexion pour lire sa révocation, par la
     * connexion DBAL : une suppression en masse, sans instance à charger.
     * L'instant est typé comme sa colonne, avec fuseau (#434). Le lien d'un
     * rafraîchissement vers un jeton supprimé est remis à NULL par la base
     * (`ON DELETE SET NULL`). Aucun index sur `expires_at` : la table reste
     * bornée par cette purge quotidienne, à revoir si la volumétrie le demande.
     */
    public function deleteDead(DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM mcp_access_token t USING mcp_connection c'
            .' WHERE c.id = t.connection_id AND (t.expires_at <= :now OR c.revoked_at IS NOT NULL)',
            ['now' => $now],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
