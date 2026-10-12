<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Note qu'un `flush()` a eu quelque chose à écrire pendant une opération de
 * {@see \App\Shared\Infrastructure\Lock\PostgresLockedTransaction} (issue #549).
 *
 * Branché par le squelette sur l'événement `onFlush` de l'EntityManager le
 * temps d'une seule transaction, puis débranché : un objet par transaction,
 * jamais un service partagé, pour qu'une écriture validée par une
 * transaction ne soit pas reprochée à la suivante.
 *
 * `onFlush` plutôt que `postFlush` : Doctrine émet `postFlush` même pour un
 * flush qui n'avait rien à écrire, et un refus levé après lui n'a rien laissé
 * de périmé. À `onFlush`, les changements planifiés sont connus ; s'il y en a,
 * le flush écrit, ou échoue et ferme l'EntityManager de lui-même
 * ({@see \Doctrine\ORM\UnitOfWork::commit()}). Une écriture DQL
 * (`UPDATE`/`DELETE`) ou DBAL brute ne passe pas par l'UnitOfWork et n'est
 * pas notée. Limite acceptée, sans appelant sous un verrou du squelette : ce
 * que le code reporte lui-même en mémoire après une telle écriture — une
 * entité chargée ou rafraîchie ensuite, ou des données d'origine synchronisées
 * à la main comme le fait `CpgUserRepository::advanceInvitationGeneration()`
 * (appelé par la relance, hors verrou) — garde, après l'exception, la valeur
 * que le ROLLBACK annule. Un tel motif placé un jour sous un verrou doit
 * passer par `flush()`, ou fermer lui-même.
 */
final class WrittenFlushWatch
{
    private bool $written = false;

    /**
     * Appelé par l'EventManager de Doctrine, d'après le nom de l'événement.
     */
    public function onFlush(OnFlushEventArgs $event): void
    {
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();

        $this->written = $this->written
            || [] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityUpdates()
            || [] !== $unitOfWork->getScheduledEntityDeletions()
            || [] !== $unitOfWork->getScheduledCollectionUpdates()
            || [] !== $unitOfWork->getScheduledCollectionDeletions();
    }

    /** Un `flush()` a eu quelque chose à écrire depuis que la montre est branchée. */
    public function sawAWrite(): bool
    {
        return $this->written;
    }
}
