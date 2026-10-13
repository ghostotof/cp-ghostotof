<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use Closure;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Le verrou de ligne du compte (`SELECT … FOR UPDATE`), dans une transaction
 * de la connexion (issues #435, #458).
 *
 * - Le squelette des verrous PostgreSQL, {@see PostgresLockedTransaction}
 *   (issue #512) : une transaction à la main — un refus levé par l'opération
 *   avant toute écriture (un lien déjà utilisé, 404) est une issue ordinaire,
 *   qui ne doit pas fermer l'EntityManager ; une exception levée après une
 *   écriture le ferme (issue #549, le `DELETE` DQL qui suit les `save()` de
 *   l'activation), et un COMMIT refusé remonte sous l'erreur de la base,
 *   l'EntityManager fermé (issue #547) —, l'attente bornée si la
 *   session ne la borne pas (un filet depuis #525, où le worker Messenger et
 *   les Jobs ont reçu le `PGOPTIONS` de FPM), son `55P03` renommé.
 * - **Une seule requête** verrouille la ligne, dit si elle existe et relit
 *   le compte sur place ({@see RereadUnderLock::row()}, `FOR UPDATE`,
 *   issue #560) : l'opération reçoit l'instance relue. `refresh()` sur un
 *   compte supprimé entre-temps ne lève rien, et l'écriture suivante échouait
 *   sur la clé étrangère. Disparu, le compte est introuvable et l'instance que
 *   l'appelant tient encore est détachée et retenue (#548) : restée gérée, une
 *   modification puis un `save()` auraient émis un `UPDATE` sur 0 ligne, perdu
 *   en silence. Avant #560, un `SELECT 1 … FOR UPDATE` puis un `refresh()` :
 *   deux requêtes, et rien de détaché.
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
        private RereadUnderLock $rereadUnderLock,
        private string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function whileLocked(CpgUser $user, Closure $operation): mixed
    {
        $id = $user->getId();
        // La relecture rafraîchit l'instance gérée : une autre, non gérée,
        // laisserait l'appelant avec la sienne, périmée (#560).
        if (!$this->entityManager->contains($user)) {
            throw AccountLockOnUnmanagedAccountException::forAccount($id->toRfc4122());
        }

        return new PostgresLockedTransaction($this->entityManager, $this->logger, $this->fallbackLockTimeout)->run(
            // Une requête verrouille la ligne, dit si elle existe et relit le
            // compte sur place ; disparu, son instance est détachée (#560).
            acquire: fn (): CpgUser => $this->rereadUnderLock->row($this->entityManager, CpgUser::class, $id, forUpdate: true)
                ?? throw CpgUserNotFoundException::forId($id),
            onTimeout: static fn (DriverException $timeout): AccountLockTimeoutException => AccountLockTimeoutException::forAccount($id->toRfc4122(), $timeout),
            onNested: static fn (): AccountLockInsideTransactionException => AccountLockInsideTransactionException::forAccount($id->toRfc4122()),
            operation: $operation,
        );
    }
}
