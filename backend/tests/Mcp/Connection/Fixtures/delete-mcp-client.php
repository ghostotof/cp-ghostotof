<?php

declare(strict_types=1);

/*
 * Processus fils de ConsentClientRemovalRaceTest (#652, report de T8) :
 * supprime la ligne d'un client MCP en SQL natif pendant que le test accorde
 * un consentement à ce client — l'administrateur qui retire le client au
 * moment où la personne clique « Autoriser ». Le DELETE cascade sur les
 * connexions et leurs jetons (ON DELETE CASCADE).
 *
 * Usage : php delete-mcp-client.php <fichier-barrière> <uuid-du-client>
 *
 * Aucun secret : un identifiant de ligne.
 *
 * Déroulé :
 *  1. démarre le kernel de test, ouvre sa connexion PostgreSQL et borne ses
 *     instructions à 10 s (`statement_timeout`, le filet des tests de
 *     concurrence : un verrou jamais rendu ne doit pas suspendre le test) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. supprime la ligne ; si l'accord tient la ligne `FOR SHARE`, l'instruction
 *     attend son COMMIT ;
 *  4. écrit « deleted <lignes supprimées> » et sort en 0 ; « failed <SQLSTATE> »
 *     et sort en 3 si la base l'a refusée (jamais le message du pilote) ; sort
 *     en ChildPhpProcess::SETUP_FAILED s'il n'a pas pu se mettre en place.
 */

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $clientId] = $arguments + [null, null, null];
if (!\is_string($barrierPath) || !\is_string($clientId)) {
    fwrite(\STDERR, "Usage : php delete-mcp-client.php <fichier-barrière> <uuid-du-client>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$connection = $entityManager->getConnection();
$connection->executeStatement("SET statement_timeout = '10s'");
$pid = $connection->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

try {
    $deleted = $connection->executeStatement('DELETE FROM mcp_client WHERE id = :id', ['id' => $clientId]);
} catch (Throwable $failure) {
    // Le SQLSTATE seul (ou la classe, hors base) : le message du pilote
    // recopie l'instruction.
    echo 'failed '.($failure instanceof DriverException ? ($failure->getSQLState() ?? 'inconnu') : $failure::class)."\n";
    exit(3);
}

echo \sprintf("deleted %d\n", $deleted);

exit(0);
