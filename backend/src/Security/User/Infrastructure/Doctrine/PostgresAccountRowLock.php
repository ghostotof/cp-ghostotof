<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use Closure;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Le verrou de ligne du compte (`SELECT … FOR UPDATE`), dans une transaction
 * de la connexion (issues #435, #458).
 *
 * - Le squelette des verrous PostgreSQL, {@see PostgresLockedTransaction}
 *   (issue #512) : une transaction à la main — un refus levé par l'opération
 *   (un lien déjà utilisé, 404) est une issue ordinaire, qui ne doit pas
 *   fermer l'EntityManager, et un COMMIT refusé remonte sous l'erreur de la
 *   base, l'EntityManager fermé (issue #547) —, l'attente bornée si la
 *   session ne la borne pas (un filet depuis #525, où le worker Messenger et
 *   les Jobs ont reçu le `PGOPTIONS` de FPM), son `55P03` renommé.
 * - Une lecture qui dit si la ligne existe : `refresh()` sur un compte
 *   supprimé entre-temps ne lève rien, et l'écriture suivante échouait sur la
 *   clé étrangère.
 * - **Jamais dans une transaction déjà ouverte** (issue #532) : le squelette
 *   le refuse, ici sous {@see AccountLockInsideTransactionException}.
 *   Imbriqué, son COMMIT ne serait qu'un point de sauvegarde : la ligne
 *   resterait verrouillée jusqu'au COMMIT de l'appelant, et le handler
 *   d'invitation enverrait son lien avant la validation du jeton.
 */
final readonly class PostgresAccountRowLock implements AccountLockInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function whileLocked(CpgUser $user, Closure $operation): mixed
    {
        $connection = $this->entityManager->getConnection();

        return new PostgresLockedTransaction($this->entityManager, $this->logger, $this->fallbackLockTimeout)->run(
            acquire: static function () use ($connection, $user): void {
                $locked = $connection->fetchOne(
                    'SELECT 1 FROM cpg_user WHERE id = :id FOR UPDATE',
                    ['id' => $user->getId()],
                    ['id' => UuidType::NAME],
                );
                if (false === $locked) {
                    throw CpgUserNotFoundException::forId($user->getId());
                }
            },
            onTimeout: static fn (DriverException $timeout): AccountLockTimeoutException => AccountLockTimeoutException::forAccount($user->getId()->toRfc4122(), $timeout),
            onNested: static fn (): AccountLockInsideTransactionException => AccountLockInsideTransactionException::forAccount($user->getId()->toRfc4122()),
            operation: function () use ($user, $operation): mixed {
                $this->entityManager->refresh($user);

                return $operation($user);
            },
        );
    }
}
