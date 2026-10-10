<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

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
 * Limite connue : la carte d'identité de Doctrine. Une entité déjà chargée
 * avant le verrou garde les valeurs lues alors — c'est le cas de l'entrée d'un
 * `PUT`, que le provider charge avant le processor. Sans conséquence : sa
 * propre position est réécrite (rattacher, détacher) ou laissée telle quelle,
 * et ne sert jamais à placer une autre entrée.
 */
final readonly class PostgresAdvisoryOrderScopeLock implements OrderScopeLockInterface
{
    /**
     * Première clé du verrou, commune à tous les périmètres d'ordre : « ORDR »
     * en ASCII. La seconde est `hashtext()` du périmètre, le nom de la classe
     * d'entité qui porte les positions.
     */
    public const int ADVISORY_NAMESPACE = 0x4F524452;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function withLock(string $scope, Closure $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(
            static function (Connection $connection) use ($scope, $operation): mixed {
                // Bloquant : attend la fin de la transaction qui tient ce
                // périmètre. Sans délai propre : celui d'une requête web est le
                // lock_timeout de 5 s de www.prod.conf (#272), largement
                // au-dessus des quelques millisecondes d'une écriture d'ordre.
                $connection->executeQuery(
                    'SELECT pg_advisory_xact_lock(?, hashtext(?))',
                    [self::ADVISORY_NAMESPACE, $scope],
                );

                return $operation();
            },
        );
    }
}
