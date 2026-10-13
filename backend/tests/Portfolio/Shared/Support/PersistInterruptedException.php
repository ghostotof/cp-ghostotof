<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Support;

use LogicException;

/**
 * Levée par {@see InterruptsTheNthPersist} : la panne simulée au milieu d'un
 * peuplement, reconnaissable à sa classe pour qu'un test ne la confonde avec
 * aucune autre exception du chemin.
 */
final class PersistInterruptedException extends LogicException
{
    public static function atPersist(int $ordinal, string $entityClass): self
    {
        return new self(\sprintf('Panne simulée au %de persist() (%s).', $ordinal, $entityClass));
    }
}
