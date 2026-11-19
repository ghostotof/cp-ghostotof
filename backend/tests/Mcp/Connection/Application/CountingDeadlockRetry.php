<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Security\User\Application\DeadlockRetryInterface;
use Closure;

/**
 * La relance d'interblocage de l'application, qui compte les opérations
 * qu'on lui confie : de quoi prouver qu'un service enveloppe **toute** sa
 * transaction verrouillée dans `retryOnce()` (#652, T1), sans provoquer
 * d'interblocage — celui-là est éprouvé par un test de concurrence réel.
 *
 * Non readonly : il compte.
 */
final class CountingDeadlockRetry implements DeadlockRetryInterface
{
    private int $operations = 0;

    public function __construct(
        private readonly DeadlockRetryInterface $inner,
    ) {
    }

    public function retryOnce(Closure $attempt): mixed
    {
        ++$this->operations;

        return $this->inner->retryOnce($attempt);
    }

    /** Le nombre d'opérations confiées à `retryOnce()` depuis la construction. */
    public function operations(): int
    {
        return $this->operations;
    }
}
