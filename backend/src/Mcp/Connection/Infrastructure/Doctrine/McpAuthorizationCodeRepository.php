<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
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

    /**
     * Relu tel qu'en base (`HINT_REFRESH`), jamais tel que l'EntityManager le
     * tient (#649) : un code relu sous le verrou de sa connexion doit montrer
     * l'échange qu'une requête concurrente a validé entre-temps, et sans
     * l'indice Doctrine rendrait l'instance déjà gérée sans la rafraîchir. La
     * connexion jointe est rafraîchie de même.
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
            ->setHint(Query::HINT_REFRESH, true)
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

    /**
     * Une instruction, jointe à la connexion pour lire sa révocation, par la
     * connexion DBAL : une suppression en masse, sans instance à charger.
     * L'instant est typé comme sa colonne, avec fuseau (#434). Un code échangé
     * non échu d'une connexion active n'est pas visé : sa ligne fait
     * reconnaître son rejeu. Aucun index sur `expires_at` : la table reste
     * bornée par cette purge quotidienne, à revoir si la volumétrie le demande.
     */
    public function deleteDead(DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM mcp_authorization_code t USING mcp_connection c'
            .' WHERE c.id = t.connection_id AND (t.expires_at <= :now OR c.revoked_at IS NOT NULL)',
            ['now' => $now],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
