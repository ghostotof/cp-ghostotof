<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use LogicException;

/**
 * `withLocks()` a été appelé sans aucun périmètre (issue #567) : un défaut de
 * l'appelant, refusé avant d'ouvrir quoi que ce soit. Sans périmètre, le
 * verrou n'aurait qu'une transaction à offrir, et ne sérialiserait rien de ce
 * que l'opération écrit. Le type `non-empty-list` de l'interface le refuse
 * déjà à l'analyse statique ; ceci couvre une liste construite à l'exécution.
 */
final class OrderScopeLockWithoutScopeException extends LogicException
{
    public static function noScopeGiven(): self
    {
        return new self("withLocks() demande au moins un périmètre d'ordre : sans lui, la transaction ne sérialiserait rien.");
    }
}
