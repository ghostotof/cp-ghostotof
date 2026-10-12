<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\UsernameAllocationLockInterface;
use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
 * - Le squelette — transaction à la main, attente bornée si la session ne
 *   la borne pas (un filet depuis #525 : la console de dev ou de CI),
 *   `55P03` renommé, COMMIT refusé remonté sous
 *   l'erreur de la base — est celui des verrous PostgreSQL,
 *   {@see PostgresLockedTransaction} (issue #512). Un refus levé par
 *   l'opération ne ferme pas, à lui seul, l'EntityManager ; celui que lève
 *   un `flush()` le ferme, comme avant ce verrou, et un COMMIT refusé par la
 *   base aussi (issue #547).
 * - **Jamais dans une transaction déjà ouverte** (issue #532) : le squelette
 *   le refuse, ici sous {@see UsernameAllocationLockInsideTransactionException}.
 *   Imbriqué, son COMMIT ne serait qu'un point de sauvegarde, et
 *   `CpgUserInviter::invite()` publierait avant la validation du compte.
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

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function whileAllocating(Closure $operation): mixed
    {
        $connection = $this->entityManager->getConnection();

        return new PostgresLockedTransaction($this->entityManager, $this->logger, $this->fallbackLockTimeout)->run(
            acquire: static fn (): mixed => $connection->executeQuery(
                'SELECT pg_advisory_xact_lock(?, ?)',
                [self::ADVISORY_NAMESPACE, self::ADVISORY_KEY],
            ),
            onTimeout: UsernameAllocationLockTimeoutException::afterWaiting(...),
            onNested: UsernameAllocationLockInsideTransactionException::create(...),
            operation: $operation,
        );
    }
}
