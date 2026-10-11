<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use Closure;
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
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function whileLocked(CpgUser $user, Closure $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(function () use ($user, $operation): mixed {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement("SET LOCAL lock_timeout = '5s'");
            $locked = $connection->fetchOne(
                'SELECT 1 FROM cpg_user WHERE id = :id FOR UPDATE',
                ['id' => $user->getId()],
                ['id' => UuidType::NAME],
            );
            if (false === $locked) {
                throw CpgUserNotFoundException::forId($user->getId());
            }

            $this->entityManager->refresh($user);

            return $operation($user);
        });
    }
}
