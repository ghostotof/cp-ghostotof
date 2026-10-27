<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<McpConnection>
 */
class McpConnectionRepository extends ServiceEntityRepository implements McpConnectionRepositoryInterface
{
    /**
     * Les trois choix de connexions de {@see self::revokeWhere()}, alias `c`,
     * écrits ici seuls : aucun appelant ne compose de SQL. Une ligne au plus,
     * l'identifiant étant la clé primaire ({@see self::revokeActive()}).
     */
    private const string WHERE_ONE = 'c.id = :id';

    /** Les autres connexions actives du couple compte/client ({@see self::revokeOtherActiveOf()}). */
    private const string WHERE_OTHERS_OF_PAIR = 'c.account_id = :account AND c.mcp_client_id = :client AND c.id <> :kept';

    /** Une liste d'identifiants ({@see self::revokeExpired()}). */
    private const string WHERE_AMONG = 'c.id IN (:ids)';

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

    /**
     * Le client est joint (`innerJoin` + `addSelect`) : la liste affiche son
     * nom, et une jointure évite une requête par connexion. Le compte n'est
     * pas joint — l'appelant le tient déjà, et la colonne `account_id` suffit
     * au filtre ; seul son identifiant est lu. L'identifiant du compte est
     * typé comme sa colonne.
     */
    public function findAllOfAccount(Uuid $accountId): array
    {
        /** @var list<McpConnection> */
        return $this->createQueryBuilder('connection')
            ->addSelect('client')
            ->innerJoin('connection.client', 'client')
            ->where('IDENTITY(connection.account) = :account')
            ->setParameter('account', $accountId, UuidType::NAME)
            ->orderBy('connection.grantedAt', 'DESC')
            ->addOrderBy('connection.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function add(McpConnection $connection): void
    {
        $this->getEntityManager()->persist($connection);
        $this->getEntityManager()->flush();
    }

    /**
     * Du SQL natif par la connexion DBAL de l'EntityManager, comme les
     * révocations : l'audit d'un refus au point de jeton la lit après une
     * transaction que `wrapInTransaction()` a annulée et dont l'EntityManager
     * est fermé (#725). Une requête, la jointure lit le client.
     */
    public function referenceOf(Uuid $connectionId): ?ConnectionReference
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT c.id, c.account_id, cl.client_id FROM mcp_connection c'
            .' INNER JOIN mcp_client cl ON cl.id = c.mcp_client_id'
            .' WHERE c.id = :id',
            ['id' => $connectionId],
            ['id' => UuidType::NAME],
        );

        return false === $row ? null : $this->referenceInRow($row);
    }

    /**
     * La relecture sous verrou du projet ({@see RereadUnderLock::row()},
     * `.claude/rules/backend-architecture.md`) : une requête DQL `FOR UPDATE`
     * avec `HINT_REFRESH`, jamais une copie. Le verrou tient jusqu'au COMMIT
     * ou au ROLLBACK de la transaction qui le prend — celle du point de jeton
     * ou du point de révocation ; sous FPM, l'attente
     * est bornée par le `lock_timeout` de la session (5 s). Le compte et le
     * client ne sont pas joints : ce que l'appelant en lit ensuite (les
     * rôles stockés) est chargé à la demande, sous le même verrou.
     */
    public function lockForUpdate(Uuid $connectionId): ?McpConnection
    {
        return $this->rereadUnderLock->row($this->getEntityManager(), McpConnection::class, $connectionId, forUpdate: true);
    }

    public function save(McpConnection $connection): void
    {
        $this->getEntityManager()->persist($connection);
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
        // Une ligne au plus : l'identifiant est la clé primaire.
        return $this->revokeWhere(self::WHERE_ONE, ['id' => $connectionId], ['id' => UuidType::NAME], $cause, $at)[0] ?? null;
    }

    /**
     * La même instruction que {@see self::revokeActive()}, sur un couple au
     * lieu d'un identifiant : `RETURNING` rend chaque connexion remplacée, de
     * quoi l'auditer sans seconde requête. La recherche du couple actif est
     * servie par l'index partiel `idx_mcp_connection_active_pair`
     * (`account_id, mcp_client_id WHERE revoked_at IS NULL`). Elle ne
     * verrouille que les lignes des **autres** connexions du couple, dans
     * l'ordre des identifiants comme la purge ({@see self::revokeWhere()}),
     * jamais celles de leurs jetons : appelée par l'échange d'un code (#726), après
     * la connexion de ce code et ses jetons, elle n'inverse pas l'ordre d'un
     * rafraîchissement (sa connexion, puis ses jetons) — un rafraîchissement
     * qui tient le verrou d'une connexion remplacée fait attendre l'échange
     * jusqu'à son COMMIT, puis la ligne est relue (`revoked_at IS NULL`
     * réévalué ; `TokenEndpointConcurrencyTest`).
     */
    public function revokeOtherActiveOf(McpConnection $kept, RevocationCause $cause, DateTimeImmutable $at): array
    {
        return $this->revokeWhere(
            self::WHERE_OTHERS_OF_PAIR,
            ['account' => $kept->account()->getId(), 'client' => $kept->client()->getId(), 'kept' => $kept->getId()],
            ['account' => UuidType::NAME, 'client' => UuidType::NAME, 'kept' => UuidType::NAME],
            $cause,
            $at,
        );
    }

    /**
     * La relecture par critère du projet ({@see RereadUnderLock::rowsMatching()},
     * `.claude/rules/backend-architecture.md`) : une requête DQL `FOR UPDATE`
     * avec `HINT_REFRESH`, jamais une copie — une instance déjà gérée est relue
     * telle que le verrou la montre. Sur la connexion seule (une jointure
     * étendrait le `FOR UPDATE` aux lignes du compte et du client), ordonnée
     * par identifiant pour que deux purges lancées ensemble prennent leurs
     * verrous dans le même ordre. Les bornes sont typées comme leurs colonnes,
     * avec fuseau (#434). Doctrine refuse le verrou hors transaction
     * (`TransactionRequiredException`).
     */
    public function findActiveCandidatesForExpiry(DateTimeImmutable $idleBefore, DateTimeImmutable $maximumBefore): array
    {
        return $this->rereadUnderLock->rowsMatching(
            $this->getEntityManager(),
            McpConnection::class,
            static function (QueryBuilder $query, string $connection) use ($idleBefore, $maximumBefore): void {
                $query->andWhere($connection.'.revokedAt IS NULL')
                    ->andWhere('(COALESCE('.$connection.'.lastUsedAt, '.$connection.'.grantedAt) <= :idleBefore OR '.$connection.'.grantedAt <= :maximumBefore)')
                    ->orderBy($connection.'.id')
                    ->setParameter('idleBefore', $idleBefore, Types::DATETIMETZ_IMMUTABLE)
                    ->setParameter('maximumBefore', $maximumBefore, Types::DATETIMETZ_IMMUTABLE);
            },
            forUpdate: true,
        );
    }

    /**
     * La même instruction que {@see self::revokeActive()}, sur une liste
     * d'identifiants, cause `expired` : `revoked_at IS NULL` garde la première
     * révocation, `RETURNING` rend chaque connexion révoquée pour l'audit. Une
     * liste vide n'interroge pas la base.
     *
     * **La liste est typée par le type de sa colonne** (#726, T6) : chaque
     * identifiant est converti par UuidType (`UuidType::NAME`), comme
     * `:id` ou `:kept`, puis lié en chaînes. DBAL 4 n'admet pas de type
     * personnalisé pour une liste (`ArrayParameterType` n'a que `INTEGER`,
     * `STRING`, `ASCII` et `BINARY`, la forme `type + ARRAY_PARAM_OFFSET` de
     * DBAL 2 et 3 a disparu) : la conversion explicite par la connexion est
     * la seule qu'il laisse, celle de {@see RereadUnderLock} déjà. Sur
     * PostgreSQL elle rend le texte de l'uuid natif, que la base compare à la
     * colonne `uuid` ; un `toRfc4122()` écrit à la main aurait décidé à la
     * place du type (binaire sur une plateforme sans uuid natif).
     *
     * **`ArrayParameterType::STRING` est choisi à la main, pour l'uuid natif
     * de PostgreSQL** (#726) : la conversion suit la plateforme, pas le type
     * de la liste. Une plateforme sans uuid natif rendrait les 16 octets
     * bruts, à lier en `ArrayParameterType::BINARY` — un changement de base
     * reprend ce choix ici, la conversion seule n'y suffit pas.
     */
    public function revokeExpired(array $connectionIds, DateTimeImmutable $at): array
    {
        if ([] === $connectionIds) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();

        return $this->revokeWhere(
            self::WHERE_AMONG,
            ['ids' => array_map(static fn (Uuid $id): mixed => $connection->convertToDatabaseValue($id, UuidType::NAME), $connectionIds)],
            ['ids' => ArrayParameterType::STRING],
            RevocationCause::Expired,
            $at,
        );
    }

    /**
     * Une instruction : le seuil est strict, typé comme sa colonne. Les codes
     * et jetons suivent par `ON DELETE CASCADE` (la cascade les emporte, s'il
     * en reste : ceux d'une connexion révoquée sont morts, et la purge les a
     * supprimés la nuit qui a suivi la révocation). La purge l'appelle avant
     * ses suppressions de jetons, pour verrouiller ses connexions d'abord.
     */
    public function deleteRevokedBefore(DateTimeImmutable $threshold): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM mcp_connection WHERE revoked_at < :threshold',
            ['threshold' => $threshold],
            ['threshold' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * L'instruction des trois révocations, écrite une fois (lot C des
     * relectures de #649) : seul le choix des connexions change, `$where`,
     * l'une des trois constantes `WHERE_*` de cette classe — jamais une chaîne
     * composée par un appelant.
     * Du SQL natif par la connexion DBAL de l'EntityManager, jamais l'unité de
     * travail ni le DQL (`.claude/rules/mcp-oauth-cycle-de-vie.md`) : la
     * révocation du point de jeton suit une transaction que
     * `wrapInTransaction()` a annulée **et** fermé l'EntityManager, et
     * `RETURNING` rend ce qui a été révoqué — compte et identifiant public du
     * client par la jointure — sans seconde requête. `revoked_at IS NULL`
     * garde la première révocation, la jointure lit le client ; l'instant est
     * typé comme sa colonne, avec fuseau (#434).
     *
     * **Les lignes sont verrouillées dans l'ordre des identifiants** (#726,
     * relecture finale) : la sous-requête `… ORDER BY c.id FOR NO KEY UPDATE`
     * les prend une à une dans cet ordre (le verrou suit le tri), avant que
     * l'`UPDATE` ne les écrive — un `UPDATE` seul les verrouille dans l'ordre
     * où son plan les lit, celui du tas (parcours séquentiel) ou de l'index.
     * **`FOR NO KEY UPDATE`, la force de l'`UPDATE` lui-même** (#726, T6) :
     * l'instruction ne réécrit aucune colonne de clé, PostgreSQL y prend ce
     * mode-là ; `FOR UPDATE` l'aurait renforcé sans raison, et bloqué le
     * `FOR KEY SHARE` que prend toute insertion d'une ligne qui référence la
     * connexion (un code, un jeton : la clé étrangère). Il entre en conflit
     * avec le `FOR UPDATE` de la purge et de `lockForUpdate()` comme avec un
     * autre `FOR NO KEY UPDATE` : l'ordre et l'exclusion entre révocations ne
     * changent pas. C'est
     * l'ordre où la purge verrouille ses candidates
     * ({@see self::findActiveCandidatesForExpiry()}) : sans lui, le
     * remplacement de deux autres connexions d'un couple, toutes deux
     * candidates, pouvait croiser le marquage de la purge en `40P01`
     * (`TokenEndpointConcurrencyTest::testASupersessionLocksTheOtherConnectionsInIdOrder`).
     * Une seule forme pour les trois révocations : pour une ligne
     * (`revokeActive()`) l'ordre ne change rien, pour la purge
     * (`revokeExpired()`) ses lignes sont déjà verrouillées, par identifiant,
     * dans la même transaction. Le `revoked_at IS NULL` de la sous-requête est
     * réévalué sur la ligne validée après une attente (READ COMMITTED) : une
     * connexion révoquée entre-temps n'est ni rendue ni réécrite — sa ligne
     * reste verrouillée jusqu'à la fin de la transaction, PostgreSQL prenant
     * le verrou avant de réévaluer la condition ; celui de l'`UPDATE` est
     * gardé, il le dit sur la ligne écrite.
     *
     * @param self::WHERE_*                               $where  le choix des connexions, alias `c`, sans paramètre écrit en clair
     * @param array<string, mixed>                        $params ses paramètres nommés (ni `at` ni `cause`, ceux de l'instruction)
     * @param array<string, string|ArrayParameterType>    $types  leurs types DBAL
     *
     * @return list<RevokedConnection> ce que l'instruction a effectivement révoqué
     */
    private function revokeWhere(string $where, array $params, array $types, RevocationCause $cause, DateTimeImmutable $at): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            // L'alias `c` est celui des constantes WHERE_*, dans la
            // sous-requête ; la ligne écrite s'appelle `revoked`.
            'UPDATE mcp_connection revoked SET revoked_at = :at, revocation_cause = :cause'
            .' FROM mcp_client cl'
            .' WHERE revoked.id IN (SELECT c.id FROM mcp_connection c WHERE '.$where.' AND c.revoked_at IS NULL ORDER BY c.id FOR NO KEY UPDATE)'
            .' AND revoked.revoked_at IS NULL AND cl.id = revoked.mcp_client_id'
            .' RETURNING revoked.id, revoked.account_id, cl.client_id',
            ['at' => $at, 'cause' => $cause->value, ...$params],
            ['at' => Types::DATETIMETZ_IMMUTABLE, ...$types],
        );

        return array_map(fn (array $row): RevokedConnection => $this->revokedConnectionOf($row, $cause), $rows);
    }

    /**
     * Ce qu'une ligne rendue par `RETURNING revoked.id, revoked.account_id,
     * cl.client_id` ({@see self::revokeWhere()}) a révoqué : écrit une fois
     * pour les trois révocations.
     *
     * @param array<string, mixed> $row
     */
    private function revokedConnectionOf(array $row, RevocationCause $cause): RevokedConnection
    {
        return new RevokedConnection($this->referenceInRow($row), $cause);
    }

    /**
     * La connexion que désigne une ligne `c.id, c.account_id, cl.client_id` :
     * lue une fois pour les révocations et pour {@see self::referenceOf()}.
     *
     * @param array<string, mixed> $row
     */
    private function referenceInRow(array $row): ConnectionReference
    {
        return new ConnectionReference(
            Uuid::fromString($this->text($row, 'id')),
            Uuid::fromString($this->text($row, 'account_id'))->toRfc4122(),
            $this->text($row, 'client_id'),
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
