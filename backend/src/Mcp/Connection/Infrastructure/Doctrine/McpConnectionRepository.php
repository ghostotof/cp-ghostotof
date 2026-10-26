<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<McpConnection>
 */
class McpConnectionRepository extends ServiceEntityRepository implements McpConnectionRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RereadUnderLock $rereadUnderLock,
    ) {
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

    /**
     * La relecture sous verrou du projet ({@see RereadUnderLock::row()},
     * `.claude/rules/backend-architecture.md`) : une requête DQL `FOR UPDATE`
     * avec `HINT_REFRESH`, jamais une copie. Le verrou tient jusqu'au COMMIT
     * ou au ROLLBACK de la transaction du point de jeton ; sous FPM, l'attente
     * est bornée par le `lock_timeout` de la session (5 s). Le compte et le
     * client ne sont pas joints : ce que l'appelant en lit ensuite (les
     * rôles stockés) est chargé à la demande, sous le même verrou.
     */
    public function lockForIssuance(Uuid $connectionId): ?McpConnection
    {
        return $this->rereadUnderLock->row($this->getEntityManager(), McpConnection::class, $connectionId, forUpdate: true);
    }

    public function save(McpConnection $connection): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Une instruction, par la connexion DBAL de l'EntityManager et jamais par
     * l'unité de travail : la révocation suit une transaction que
     * `wrapInTransaction()` a annulée **et** fermé l'EntityManager, et doit
     * tenir quand même (un EntityManager fermé garde sa connexion). La base
     * décide : `revoked_at IS NULL` fait de la première révocation la seule,
     * `RETURNING` rend ce qui a été révoqué — compte et identifiant public du
     * client par la jointure — sans seconde requête ni instance chargée.
     * L'instant est typé comme sa colonne, avec fuseau (#434).
     */
    public function revokeActive(Uuid $connectionId, RevocationCause $cause, DateTimeImmutable $at): ?RevokedConnection
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'UPDATE mcp_connection c SET revoked_at = :at, revocation_cause = :cause'
            .' FROM mcp_client cl'
            .' WHERE c.id = :id AND c.revoked_at IS NULL AND cl.id = c.mcp_client_id'
            .' RETURNING c.id, c.account_id, cl.client_id',
            ['at' => $at, 'cause' => $cause->value, 'id' => $connectionId],
            ['at' => Types::DATETIMETZ_IMMUTABLE, 'id' => UuidType::NAME],
        );
        if (false === $row) {
            return null;
        }

        return $this->revokedConnectionOf($row, $cause);
    }

    /**
     * La même instruction que {@see self::revokeActive()}, sur un couple au
     * lieu d'un identifiant : `RETURNING` rend chaque connexion remplacée, de
     * quoi l'auditer sans seconde requête. La recherche du couple actif est
     * servie par l'index partiel `idx_mcp_connection_active_pair`
     * (`account_id, mcp_client_id WHERE revoked_at IS NULL`). Elle ne
     * verrouille que les lignes des **autres** connexions du couple, jamais
     * celles de leurs jetons : l'ordre du point de jeton (la connexion, puis
     * ses jetons) n'est pas inversé — un rafraîchissement qui tient le verrou
     * d'une connexion remplacée fait attendre l'accord jusqu'à son COMMIT,
     * puis la ligne est relue (`revoked_at IS NULL` réévalué).
     */
    public function revokeOtherActiveOf(McpConnection $kept, RevocationCause $cause, DateTimeImmutable $at): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'UPDATE mcp_connection c SET revoked_at = :at, revocation_cause = :cause'
            .' FROM mcp_client cl'
            .' WHERE c.account_id = :account AND c.mcp_client_id = :client AND c.id <> :kept'
            .' AND c.revoked_at IS NULL AND cl.id = c.mcp_client_id'
            .' RETURNING c.id, c.account_id, cl.client_id',
            [
                'at' => $at,
                'cause' => $cause->value,
                'account' => $kept->account()->getId(),
                'client' => $kept->client()->getId(),
                'kept' => $kept->getId(),
            ],
            [
                'at' => Types::DATETIMETZ_IMMUTABLE,
                'account' => UuidType::NAME,
                'client' => UuidType::NAME,
                'kept' => UuidType::NAME,
            ],
        );

        return array_map(fn(array $row): RevokedConnection => $this->revokedConnectionOf($row, $cause), $rows);
    }

    /**
     * Ce qu'une ligne rendue par `RETURNING c.id, c.account_id, cl.client_id`
     * a révoqué : écrit une fois pour les deux révocations.
     *
     * @param array<string, mixed> $row
     */
    private function revokedConnectionOf(array $row, RevocationCause $cause): RevokedConnection
    {
        return new RevokedConnection(
            Uuid::fromString($this->text($row, 'id')),
            Uuid::fromString($this->text($row, 'account_id'))->toRfc4122(),
            $this->text($row, 'client_id'),
            $cause,
        );
    }

    /**
     * Une colonne texte de la ligne rendue (`uuid` et `varchar` arrivent en
     * chaînes de pdo_pgsql) ; tout autre type serait un défaut du schéma.
     *
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!\is_string($value)) {
            throw UnexpectedRevocationRowException::forColumn($column);
        }

        return $value;
    }
}
