<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\TransactionInterface;
use Closure;

/**
 * La vraie transaction de l'application, laissée passer la première fois et
 * annulée la seconde au moment de valider (#726) : la seconde opération
 * s'exécute entière, puis lève RolledBackOnPurposeException avant le COMMIT.
 * La purge marque dans sa première transaction et supprime dans la seconde :
 * ce double prouve que le marquage validé reste, audité, quand les
 * suppressions échouent.
 *
 * Non readonly : il compte ses appels.
 */
final class RollingBackTheSecondRun implements TransactionInterface
{
    private int $runs = 0;

    public function __construct(
        private readonly TransactionInterface $inner,
    ) {
    }

    public function run(Closure $operation): mixed
    {
        if (1 === ++$this->runs) {
            return $this->inner->run($operation);
        }

        return $this->inner->run(static function () use ($operation): never {
            $operation();

            throw new RolledBackOnPurposeException();
        });
    }
}
