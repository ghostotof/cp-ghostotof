<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\UsernameAllocationLockInterface;
use Closure;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le verrou d'attribution des identifiants (issue #364) : un advisory lock
 * PostgreSQL **de transaction**, sur la connexion de l'ORM.
 *
 * - De transaction (`pg_advisory_xact_lock`) : relâché par le COMMIT ou le
 *   ROLLBACK eux-mêmes, il ne peut ni précéder l'écriture visible, ni fuir
 *   (même raisonnement que le verrou de périmètre d'ordre, #389). Le `flush()`
 *   de l'opération s'imbrique dans la transaction (point de sauvegarde) et
 *   n'est visible des autres qu'au COMMIT. Pas d'état sur le pod (ADR 0005).
 * - Une seule clé pour toutes les invitations, pas une par partie locale :
 *   les candidats de deux bases se croisent (`jean` suffixé donne `jean2`,
 *   que `jean2@…` dérive aussi). Les invitations sont rares, les sérialiser
 *   toutes ne coûte rien.
 * - Forme à deux clés `int4`, espace distinct des verrous à une clé `bigint`
 *   de `symfony/lock` ; première clé distincte de celle du périmètre d'ordre.
 * - `Connection::transactional()` plutôt que `wrapInTransaction()`, comme le
 *   verrou du compte (#435) : un refus levé par l'opération ne ferme pas, à
 *   lui seul, l'EntityManager. Celui que lève un `flush()` le ferme, comme
 *   avant ce verrou.
 * - Attente bornée par `SET LOCAL lock_timeout` : FPM l'a déjà par
 *   `PGOPTIONS`, la console non. `SET LOCAL` s'éteint avec la transaction.
 *
 * Suppose READ COMMITTED, le niveau par défaut que rien ne change ici : la
 * première lecture après le verrou voit ce que son détenteur précédent a
 * validé.
 */
final readonly class PostgresUsernameAllocationLock implements UsernameAllocationLockInterface
{
    /** Première clé : « USER » en ASCII. */
    public const int ADVISORY_NAMESPACE = 0x55534552;

    /** Seconde clé : « NAME » en ASCII. */
    public const int ADVISORY_KEY = 0x4E414D45;

    /** SQLSTATE `lock_not_available`, levé à l'expiration de `lock_timeout`. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $lockTimeout = '5s',
    ) {
    }

    public function whileAllocating(Closure $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(function () use ($operation): mixed {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(\sprintf("SET LOCAL lock_timeout = '%s'", $this->lockTimeout));
            try {
                $connection->executeQuery(
                    'SELECT pg_advisory_xact_lock(?, ?)',
                    [self::ADVISORY_NAMESPACE, self::ADVISORY_KEY],
                );
            } catch (DriverException $exception) {
                // Le convertisseur de DBAL n'en fait pas une
                // LockWaitTimeoutException, seulement une DriverException.
                // Seule l'attente de ce verrou-ci est renommée.
                if (self::LOCK_NOT_AVAILABLE === $exception->getSQLState()) {
                    throw UsernameAllocationLockTimeoutException::afterWaiting($exception);
                }

                throw $exception;
            }

            return $operation();
        });
    }
}
