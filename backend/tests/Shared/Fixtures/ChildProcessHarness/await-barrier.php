<?php

declare(strict_types=1);

/*
 * Processus fils de ChildProcessHarnessTest (issue #403) : attend la barrière
 * comme le font les fils des tests de concurrence, puis écrit « passed ».
 *
 * Usage : php await-barrier.php <fichier-barrière> [<pid de session annoncé>]
 *
 * L'autoload suffit : le fils n'a pas besoin du kernel pour exercer le
 * protocole de la barrière.
 */

use App\Tests\Support\ProcessBarrier;

require \dirname(__DIR__, 4).'/vendor/autoload.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $sessionPid] = $arguments + [null, null, null];
if (!\is_string($barrierPath)) {
    fwrite(\STDERR, "Usage : php await-barrier.php <fichier-barrière> [<pid de session annoncé>]\n");
    exit(2);
}

ProcessBarrier::awaitInChild($barrierPath, \is_string($sessionPid) ? (int) $sessionPid : null);

echo "passed\n";

exit(0);
