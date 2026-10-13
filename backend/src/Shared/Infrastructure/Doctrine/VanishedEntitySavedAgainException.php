<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use LogicException;

/**
 * Un appelant a re-sauvé une entité dont la ligne n'existe plus : disparue
 * sous un verrou qui l'a détachée (issue #548), ou supprimée par ce même
 * EntityManager (#557, #569). Un défaut de l'appelant, jamais une issue ordinaire.
 *
 * Laissé passer, ce `persist()` réinsérait l'entité supprimée — Doctrine tient
 * pour neuve une instance qu'il ne gère pas. Refusé avant que rien ne soit
 * planifié, sans mapping HTTP : un 500 `critical` qui porte un nom, comme
 * {@see \App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockInsideTransactionException}.
 * Une fabrique par cause, chacune à message constant hors la classe et
 * l'identifiant : jamais une donnée reçue.
 */
final class VanishedEntitySavedAgainException extends LogicException
{
    /**
     * La ligne a disparu entre le chargement et le verrou qui l'a constaté.
     *
     * @param class-string $class
     */
    public static function vanishedUnderLock(string $class, string $id): self
    {
        return new self(\sprintf("L'entité %s %s a été supprimée entre son chargement et le verrou qui l'a constaté, puis détachée : la sauver de nouveau la réinsérerait. Relire l'entité sous le verrou plutôt que de sauver une instance tenue d'avant.", $class, $id));
    }

    /**
     * Ce même EntityManager a supprimé l'entité (`remove()` puis `flush()`,
     * #557, ou un `DELETE` DQL par identifiant, #569).
     *
     * @param class-string $class
     */
    public static function removedByThisManager(string $class, string $id): self
    {
        return new self(\sprintf("L'entité %s %s a été supprimée par cet EntityManager : la sauver de nouveau la réinsérerait au même identifiant. Relire l'entité si elle doit exister, ou en créer une nouvelle pour la rétablir.", $class, $id));
    }
}
