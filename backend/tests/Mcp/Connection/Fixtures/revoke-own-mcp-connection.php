<?php

declare(strict_types=1);

/*
 * Processus fils de OwnMcpConnectionRevocationConcurrencyTest (#650) : une
 * personne révoque une de ses connexions MCP (`DELETE /api/me/mcp-connections/{id}`,
 * sans le noyau HTTP : l'appel de service que fait le contrôleur) pendant que le
 * test tient la ligne de la connexion dans la transaction d'un autre worker — un
 * rafraîchissement qui écrit le dernier usage, le marquage de la purge.
 *
 * Usage : php revoke-own-mcp-connection.php <fichier-barrière> <uuid du compte> <uuid de la connexion>
 *
 * Aucun secret : les deux UUID sont des identifiants de lignes, la révocation
 * par la personne ne lit ni jeton ni code.
 *
 * Déroulé :
 *  1. démarre le kernel de test, ouvre sa connexion PostgreSQL et borne ses
 *     instructions à 3 s (`statement_timeout`, le filet des tests de
 *     concurrence : une session de console n'a pas de `lock_timeout`) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. appelle OwnMcpConnectionsInterface::revoke(), comme le contrôleur ;
 *  4. écrit « done » (le 204 : la connexion est à la personne, qu'elle ait été
 *     révoquée par cet appel ou avant) ou « not-found » (le 404), puis
 *     « audit <n> » : le nombre de lignes `mcp-connection-revoked` que CET
 *     appel a écrites (le canal `security_audit` du noyau de ce processus, que
 *     rien d'autre n'alimente) ; sort en 0 pour « done », en 1 pour « not-found » ;
 *     « failed <SQLSTATE> » et sort en 3 si la base l'a refusé (un
 *     interblocage, 40P01, ou le filet, 57014 ; la classe pour toute autre
 *     exception) — jamais le message du pilote ; sort en
 *     ChildPhpProcess::SETUP_FAILED s'il n'a pas pu se mettre en place.
 */

use App\Mcp\Connection\Application\OwnMcpConnectionsInterface;
use App\Mcp\Connection\Domain\Exception\McpConnectionNotFoundException;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Uid\Uuid;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $accountId, $connectionId] = $arguments + [null, null, null, null];
if (!\is_string($barrierPath) || !\is_string($accountId) || !\is_string($connectionId) || !Uuid::isValid($accountId) || !Uuid::isValid($connectionId)) {
    fwrite(\STDERR, "Usage : php revoke-own-mcp-connection.php <fichier-barrière> <uuid du compte> <uuid de la connexion>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$ownConnections = TestServiceLocator::locate(OwnMcpConnectionsInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
$auditLogger = TestServiceLocator::locate('monolog.logger.security_audit');
if (!$ownConnections instanceof OwnMcpConnectionsInterface || !$entityManager instanceof EntityManagerInterface || !$auditLogger instanceof Logger) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

// Le gestionnaire de test du canal d'audit (niveau `info`, monolog.yaml
// `when@test`) : ce que cet appel a écrit, tel que la production l'écrirait.
$auditHandler = null;
foreach ($auditLogger->getHandlers() as $handler) {
    if ($handler instanceof TestHandler && Level::Info === $handler->getLevel()) {
        $auditHandler = $handler;
    }
}
if (null === $auditHandler) {
    fwrite(\STDERR, "Aucun TestHandler sur le canal security_audit.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$connection = $entityManager->getConnection();
$connection->executeStatement("SET statement_timeout = '3s'");
$pid = $connection->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

// L'appelant : seul l'UUID du compte compte à `revoke()` ; la référence de
// connexion est le plus petit objet qui porte `accountId()`.
$caller = new ConnectionReference(Uuid::fromString($connectionId), Uuid::fromString($accountId)->toRfc4122(), 'caller');

try {
    $ownConnections->revoke($caller, Uuid::fromString($connectionId));
    $outcome = 'done';
    $exitCode = 0;
} catch (McpConnectionNotFoundException) {
    $outcome = 'not-found';
    $exitCode = 1;
} catch (Throwable $failure) {
    // Le SQLSTATE seul (ou la classe, hors base) : le message du pilote
    // recopie l'instruction.
    echo 'failed '.($failure instanceof DriverException ? ($failure->getSQLState() ?? 'inconnu') : $failure::class)."\n";
    exit(3);
}

$audited = 0;
foreach ($auditHandler->getRecords() as $record) {
    if ('mcp-connection-revoked' === ($record->context['event'] ?? null)) {
        ++$audited;
    }
}

echo $outcome."\n";
echo 'audit '.$audited."\n";

exit($exitCode);
