<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use LogicException;

/**
 * Un appelant a re-sauvé une entité dont la ligne avait disparu, et que le
 * verrou qui l'a constaté avait détachée (issue #548) : un défaut de
 * l'appelant, jamais une issue ordinaire.
 *
 * Laissé passer, ce `persist()` réinsérait l'entité supprimée — Doctrine tient
 * pour neuve une instance qu'il ne gère pas. Refusé avant que rien ne soit
 * planifié, sans mapping HTTP : un 500 `critical` qui porte un nom, comme
 * {@see \App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockInsideTransactionException}.
 * Le message ne cite que la classe et l'identifiant, jamais une donnée reçue.
 */
final class VanishedEntitySavedAgainException extends LogicException
{
    /**
     * @param class-string $class
     */
    public static function forEntity(string $class, string $id): self
    {
        return new self(\sprintf("L'entité %s %s a été supprimée entre son chargement et le verrou qui l'a constaté, puis détachée : la sauver de nouveau la réinsérerait. Relire l'entité sous le verrou plutôt que de sauver une instance tenue d'avant.", $class, $id));
    }
}
