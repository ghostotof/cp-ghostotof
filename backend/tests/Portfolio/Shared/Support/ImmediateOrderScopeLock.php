<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Support;

use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use Closure;

/**
 * `OrderScopeLockInterface` sans base : exécute l'opération sur-le-champ. Les
 * tests unitaires des administrateurs vérifient ce qu'ils placent, pas la
 * sérialisation (issue #389) — celle-ci est l'affaire de
 * OrderScopeLockCoverageTest et de ContributionOrderConcurrencyTest, contre
 * PostgreSQL.
 */
final readonly class ImmediateOrderScopeLock implements OrderScopeLockInterface
{
    public function withLock(string $scope, Closure $operation): mixed
    {
        return $operation();
    }
}
