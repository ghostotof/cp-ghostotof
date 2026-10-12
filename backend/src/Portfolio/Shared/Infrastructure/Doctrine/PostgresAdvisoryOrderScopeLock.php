<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Verrou de périmètre d'ordre sur un advisory lock PostgreSQL **de
 * transaction** (issue #389).
 *
 * Pourquoi `pg_advisory_xact_lock` sur la connexion de l'ORM plutôt que
 * `lock.factory` (#272) : le verrou de `symfony/lock` est un verrou de session
 * sur une seconde connexion, qu'il faudrait relâcher *après* le COMMIT de
 * l'ORM — relâché avant, l'écriture concurrente relirait l'état d'avant. Le
 * verrou de transaction est relâché par le COMMIT ou le ROLLBACK eux-mêmes :
 * il ne peut ni précéder l'écriture visible, ni fuir. Pas d'état sur le pod
 * non plus (ADR 0005) : il vit dans la base, partagé par tous les pods.
 *
 * Forme à deux clés `int4` : PostgreSQL range ces verrous dans un espace
 * distinct des verrous à une clé `bigint` qu'utilise `symfony/lock`, aucune
 * collision n'est donc possible avec ceux des limiteurs de débit.
 *
 * `Connection::transactional()`, pas `EntityManager::wrapInTransaction()` :
 * ce dernier **ferme l'EntityManager** sur toute exception, alors qu'un refus
 * métier (409 « version déjà existante ») est une issue ordinaire de
 * l'opération. Les `flush()` de l'opération s'imbriquent dans la transaction
 * (point de sauvegarde), et ne sont visibles des autres qu'au COMMIT.
 *
 * Ce que l'EntityManager a chargé **avant** le verrou est relu une fois le
 * verrou pris (issue #402) : `find()` rendrait l'instance de la carte
 * d'identité, et une requête ne réhydrate pas une entité déjà gérée. Or le
 * provider d'un `PUT` charge l'entrée avant le processor, et une commande qui
 * enchaîne les opérations (`app:*:seed`) garde tout le périmètre chargé : une
 * écriture concurrente validée entre-temps était défaite sans le dire — un
 * rattachement vu comme un non-geste, un détachement sauté, une version créée
 * à la position que son groupe quittait, une entrée supprimée « mise à jour »
 * en 200. Les instances gérées de la classe du périmètre sont donc relues, en
 * une requête : celles que la base porte encore sont rafraîchies sur place
 * (même objet, qu'un appelant peut continuer de tenir et de sauver), celles
 * qu'elle ne rend plus — supprimées entre-temps — sont détachées, et `find()`
 * les dit introuvables. Pas `refresh()` entrée par entrée : il ne voit pas une
 * ligne supprimée (il n'hydrate rien et garde l'instance), et coûte une
 * requête par entrée. Pas de détachement de tout le périmètre non plus : une
 * instance tenue d'avant l'opération, sauvée ensuite, entrerait en collision
 * avec celle que l'opération aurait rechargée. Ce qui en découle pour
 * l'appelant : une modification non écrite d'une de ces instances est
 * écrasée — d'où le corps entier de chaque opération dans `withLock()`.
 *
 * Cela suppose READ COMMITTED, le niveau par défaut de PostgreSQL, que rien ne
 * change ici : chaque instruction y voit ce qui a été validé avant elle, donc
 * la première lecture après le verrou voit l'écriture du détenteur précédent.
 * En REPEATABLE READ, l'instantané serait pris par le `SELECT` du verrou
 * lui-même, avant l'attente. `ScopeLockReadsCommittedStateTest` épingle les
 * deux.
 */
final readonly class PostgresAdvisoryOrderScopeLock implements OrderScopeLockInterface
{
    /**
     * Première clé du verrou, commune à tous les périmètres d'ordre : « ORDR »
     * en ASCII. La seconde est `hashtext()` du périmètre, le nom de la classe
     * d'entité qui porte les positions.
     */
    public const int ADVISORY_NAMESPACE = 0x4F524452;

    /**
     * SQLSTATE `lock_not_available`, levé à l'expiration de `lock_timeout`.
     * Le convertisseur de DBAL n'en fait pas une `LockWaitTimeoutException`,
     * seulement une `DriverException`.
     */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function withLock(string $scope, Closure $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(
            function (Connection $connection) use ($scope, $operation): mixed {
                // Bloquant : attend la fin de la transaction qui tient ce
                // périmètre. Sans délai propre : celui d'une requête web est le
                // lock_timeout de 5 s de www.prod.conf (#272), largement
                // au-dessus des quelques millisecondes d'une écriture d'ordre.
                try {
                    $connection->executeQuery(
                        'SELECT pg_advisory_xact_lock(?, hashtext(?))',
                        [self::ADVISORY_NAMESPACE, $scope],
                    );
                } catch (DriverException $exception) {
                    // Seule l'attente de ce verrou-ci est renommée : un délai
                    // dépassé dans l'opération remonte tel quel.
                    if (self::LOCK_NOT_AVAILABLE === $exception->getSQLState()) {
                        throw OrderScopeLockTimeoutException::forScope($scope, $exception);
                    }

                    throw $exception;
                }

                $this->rereadEntriesLoadedBefore($scope);

                return $operation();
            },
        );
    }

    /**
     * Relit en base les instances de `$scope` que l'EntityManager gère déjà
     * (issue #402, voir plus haut) : rafraîchies si la ligne existe encore,
     * détachées sinon. Rien à relire, aucune requête — le cas d'un `POST`.
     *
     * @param class-string $scope
     */
    private function rereadEntriesLoadedBefore(string $scope): void
    {
        // Une classe non mappée n'a aucune instance gérée : rien à relire.
        if ($this->entityManager->getMetadataFactory()->isTransient($scope)) {
            return;
        }

        $metadata = $this->entityManager->getClassMetadata($scope);
        $loaded = $this->entityManager->getUnitOfWork()->getIdentityMap()[$metadata->rootEntityName] ?? [];
        if ([] === $loaded) {
            return;
        }

        $identifier = $metadata->getSingleIdentifierFieldName();
        $identifierType = $metadata->getTypeOfField($identifier) ?? Types::STRING;
        $connection = $this->entityManager->getConnection();
        $ids = array_map(
            static fn (object $entry): mixed => $connection->convertToDatabaseValue($metadata->getIdentifierValues($entry)[$identifier], $identifierType),
            array_values($loaded),
        );

        /** @var list<object> $reread la requête rend les instances déjà gérées, rafraîchies */
        $reread = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from($metadata->rootEntityName, 'entry')
            ->andWhere(\sprintf('entry.%s IN (:ids)', $identifier))
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $stillStored = array_map(spl_object_id(...), $reread);
        foreach ($loaded as $entry) {
            if (!\in_array(spl_object_id($entry), $stillStored, true)) {
                $this->entityManager->detach($entry);
            }
        }
    }
}
