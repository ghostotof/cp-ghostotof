<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use RuntimeException;

/** L'échec que RollingBackTransaction lève avant le COMMIT, et rien d'autre. */
final class RolledBackOnPurposeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transaction annulée par le test avant son COMMIT.');
    }
}
