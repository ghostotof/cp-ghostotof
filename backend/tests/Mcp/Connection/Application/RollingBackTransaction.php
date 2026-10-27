<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\TransactionInterface;
use Closure;

/**
 * La vraie transaction de l'application, annulée au moment de valider :
 * l'opération s'y exécute entière, puis lève RolledBackOnPurposeException
 * avant le COMMIT. Un appelant qui écrirait son audit **dans** l'opération
 * laisserait une ligne pour une écriture annulée.
 */
final readonly class RollingBackTransaction implements TransactionInterface
{
    public function __construct(
        private TransactionInterface $inner,
    ) {
    }

    public function run(Closure $operation): mixed
    {
        return $this->inner->run(static function () use ($operation): never {
            $operation();

            throw new RolledBackOnPurposeException();
        });
    }
}
