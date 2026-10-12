<?php

declare(strict_types=1);

/*
 * Processus fils de FlushInterleavingTest (issue #510) : attend la barrière en
 * annonçant le pid de sa session PostgreSQL, comme les fils des tests de
 * concurrence, puis fait ce que le mode demande.
 *
 * Usage : php act-after-barrier.php <fichier-barrière> <mode> [<clé d'advisory lock>]
 *
 * Modes :
 *  - finish : écrit « released » et sort aussitôt ;
 *  - lock   : écrit « released », prend l'advisory lock de la clé (et l'attend
 *             si le test le tient), écrit « locked », le relâche et sort ;
 *  - idle   : écrit « released » puis dort sans rien attendre en base, ni fini
 *             ni bloqué, jusqu'à ce que le test l'arrête.
 *
 * Code de sortie 0, ou ChildPhpProcess::SETUP_FAILED si le fils n'a pas pu se
 * mettre en place.
 */

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\ORM\EntityManagerInterface;

require \dirname(__DIR__, 3).'/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $mode, $lockKey] = $arguments + [null, null, null, null];
if (!\is_string($barrierPath) || !\in_array($mode, ['finish', 'lock', 'idle'], true) || ('lock' === $mode && (!\is_string($lockKey) || !ctype_digit($lockKey)))) {
    fwrite(\STDERR, "Usage : php act-after-barrier.php <fichier-barrière> finish|lock|idle [<clé d'advisory lock>]\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "EntityManager introuvable dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}
$connection = $entityManager->getConnection();

$pid = $connection->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

echo "released\n";
flush();

if ('lock' === $mode) {
    $connection->executeQuery('SELECT pg_advisory_lock(?)', [(int) $lockKey]);
    echo "locked\n";
    $connection->executeQuery('SELECT pg_advisory_unlock(?)', [(int) $lockKey]);
} elseif ('idle' === $mode) {
    sleep(60);
}

exit(0);
