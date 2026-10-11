<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use Closure;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Le verrou de ligne du compte (`SELECT … FOR UPDATE`), dans une transaction
 * de la connexion (issues #435, #458).
 *
 * - `Connection::transactional()` plutôt que `wrapInTransaction()` : un refus
 *   levé par l'opération (un lien déjà utilisé, 410) est une issue ordinaire,
 *   qui ne doit pas fermer l'EntityManager (même raisonnement que le verrou de
 *   périmètre d'ordre, #389).
 * - Attente bornée par `SET LOCAL lock_timeout` : FPM l'a déjà par
 *   `PGOPTIONS`, la console (le worker Messenger) non. `SET LOCAL` s'éteint
 *   avec la transaction.
 * - Une lecture qui dit si la ligne existe : `refresh()` sur un compte
 *   supprimé entre-temps ne lève rien, et l'écriture suivante échouait sur la
 *   clé étrangère.
 */
final readonly class PostgresAccountRowLock implements AccountLockInterface
{
    /** SQLSTATE `lock_not_available`, levé à l'expiration de `lock_timeout`. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $lockTimeout = '5s',
    ) {
    }

    public function whileLocked(CpgUser $user, Closure $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(function () use ($user, $operation): mixed {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(\sprintf("SET LOCAL lock_timeout = '%s'", $this->lockTimeout));
            try {
                $locked = $connection->fetchOne(
                    'SELECT 1 FROM cpg_user WHERE id = :id FOR UPDATE',
                    ['id' => $user->getId()],
                    ['id' => UuidType::NAME],
                );
            } catch (DriverException $exception) {
                // Le convertisseur de DBAL n'en fait pas une
                // LockWaitTimeoutException, seulement une DriverException.
                if (self::LOCK_NOT_AVAILABLE === $exception->getSQLState()) {
                    throw AccountLockTimeoutException::forAccount($user->getId()->toRfc4122(), $exception);
                }

                throw $exception;
            }
            if (false === $locked) {
                throw CpgUserNotFoundException::forId($user->getId());
            }

            $this->entityManager->refresh($user);

            return $operation($user);
        });
    }
}
