<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\TransactionInterface;
use Closure;

/**
 * La vraie transaction de l'application, annulée au moment de valider lors
 * d'une seule de ses exécutions, celle du rang donné (la première par
 * défaut) : cette opération s'exécute entière, puis lève
 * RolledBackOnPurposeException avant le COMMIT ; les autres passent, validées.
 *
 * Deux usages (#726, T2 : les deux doubles d'avant fusionnés) :
 *  - rang 1 : un appelant qui écrirait son audit **dans** l'opération
 *    laisserait une ligne pour une écriture annulée (le point de jeton, une
 *    purge annulée dès son marquage) ;
 *  - rang 2 : la purge marque dans sa première transaction et supprime dans
 *    la seconde — le marquage validé reste, audité, quand les suppressions
 *    échouent.
 *
 * Non readonly : il compte ses exécutions.
 */
final class RollingBackTransaction implements TransactionInterface
{
    private int $runs = 0;

    /**
     * @param positive-int $rolledBackRun le rang de l'exécution annulée, à partir de 1
     */
    public function __construct(
        private readonly TransactionInterface $inner,
        private readonly int $rolledBackRun = 1,
    ) {
    }

    public function run(Closure $operation): mixed
    {
        if ($this->rolledBackRun !== ++$this->runs) {
            return $this->inner->run($operation);
        }

        return $this->inner->run(static function () use ($operation): never {
            $operation();

            throw new RolledBackOnPurposeException();
        });
    }
}
