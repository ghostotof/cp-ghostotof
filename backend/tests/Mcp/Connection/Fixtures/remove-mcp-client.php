<?php

declare(strict_types=1);

/*
 * Processus fils de McpClientRemovalDeadlockTest (#652, T13, constat T1) : un
 * super-administrateur supprime un client MCP — le vrai
 * McpClientAdministrator::remove(), qui verrouille le client puis ses connexions
 * actives par identifiant — pendant que le test tient une connexion du client
 * et qu'un échange de code croise ses verrous.
 *
 * Usage : php remove-mcp-client.php <fichier-barrière> <uuid du client (mcp_client.id)> [<deadlock_timeout>]
 *
 * `deadlock_timeout` (facultatif, `100ms`, `2s`…) : le délai après lequel cette session
 * cherche un cycle de verrous ; la plus courte des deux sessions croisées s'annule
 * (`40P01`). C'est ce qui désigne la victime, sans quoi PostgreSQL la choisit au
 * hasard des minuteries.
 *
 * Aucun secret : des identifiants de lignes.
 *
 * L'administrateur est assemblé ici comme dans McpClientAdministratorTest (aucune
 * route ne le câble encore dans le conteneur) ; ses collaborateurs, dont la
 * relance sur interblocage, sont ceux du conteneur de test.
 *
 * Déroulé :
 *  1. démarre le kernel de test, borne les instructions de sa connexion à 8 s
 *     (`statement_timeout`, le filet : une attente que rien ne relâcherait
 *     échoue en 57014 au lieu de suspendre la suite) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. supprime le client ;
 *  4. écrit « done », « removed <n> » (la valeur rendue : connexions coupées),
 *     « connectionaudit <n> » (`mcp-connection-revoked` écrites par CET appel),
 *     « causedaudit <n> » (parmi elles, celles de cause `client-removed`),
 *     « clientaudit <n> » (`mcp-client-removed`) et « retried <n> » (le nombre
 *     d'interblocages que la relance unique a absorbés) puis sort en 0 ;
 *     « failed <SQLSTATE> » et sort en 3 si la base l'a refusé (la classe pour
 *     toute autre exception) — jamais le message du pilote ; sort en
 *     ChildPhpProcess::SETUP_FAILED s'il n'a pas pu se mettre en place.
 */

use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Application\McpClientAdministrator;
use App\Mcp\Connection\Application\McpClientRegistrarInterface;
use App\Mcp\Connection\Application\RevokedConnectionAudit;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Application\DeadlockRetryInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $clientRowId, $deadlockTimeout] = $arguments + [null, null, null, null];
if (!\is_string($barrierPath) || !\is_string($clientRowId) || !Uuid::isValid($clientRowId) || (null !== $deadlockTimeout && (!\is_string($deadlockTimeout) || 1 !== preg_match('/^\d+(ms|s)\z/', $deadlockTimeout)))) {
    fwrite(\STDERR, "Usage : php remove-mcp-client.php <fichier-barrière> <uuid du client> [<deadlock_timeout>]\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
$auditLogger = TestServiceLocator::locate('monolog.logger.security_audit');
$securityAudit = TestServiceLocator::locate(SecurityAuditLoggerInterface::class);
$logger = TestServiceLocator::locate(LoggerInterface::class);
$registrar = TestServiceLocator::locate(McpClientRegistrarInterface::class);
$clients = TestServiceLocator::locate(McpClientRepositoryInterface::class);
$connections = TestServiceLocator::locate(McpConnectionRepositoryInterface::class);
$transaction = TestServiceLocator::locate(TransactionInterface::class);
$deadlockRetry = TestServiceLocator::locate(DeadlockRetryInterface::class);
$allowedClientDomains = TestServiceLocator::locate(AllowedClientDomains::class);
if (!$entityManager instanceof EntityManagerInterface || !$auditLogger instanceof Logger || !$securityAudit instanceof SecurityAuditLoggerInterface
    || !$logger instanceof LoggerInterface || !$registrar instanceof McpClientRegistrarInterface || !$clients instanceof McpClientRepositoryInterface
    || !$connections instanceof McpConnectionRepositoryInterface || !$transaction instanceof TransactionInterface || !$deadlockRetry instanceof DeadlockRetryInterface
    || !$allowedClientDomains instanceof AllowedClientDomains) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

// Le gestionnaire de test du canal d'audit (niveau `info`) : ce que cet appel a
// écrit ; la sonde de tous les canaux (niveau `debug`) : ce que la relance a
// journalisé.
$auditHandler = null;
$allChannelsHandler = null;
foreach ($auditLogger->getHandlers() as $handler) {
    if (!$handler instanceof TestHandler) {
        continue;
    }
    if (Level::Info === $handler->getLevel()) {
        $auditHandler = $handler;
    }
    if (Level::Debug === $handler->getLevel()) {
        $allChannelsHandler = $handler;
    }
}
if (null === $auditHandler || null === $allChannelsHandler) {
    fwrite(\STDERR, "Gestionnaires de test du journal introuvables.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$administrator = new McpClientAdministrator(
    $registrar,
    $clients,
    $connections,
    $transaction,
    $deadlockRetry,
    new RevokedConnectionAudit($securityAudit, new FailSafeAudit($logger)),
    new FailSafeAudit($logger),
    $securityAudit,
    $allowedClientDomains,
);

$connection = $entityManager->getConnection();
$connection->executeStatement("SET statement_timeout = '8s'");
if (\is_string($deadlockTimeout)) {
    $connection->executeStatement('SET deadlock_timeout = '.$connection->quote($deadlockTimeout));
}
$pid = $connection->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

try {
    $removed = $administrator->remove(Uuid::fromString($clientRowId));
} catch (Throwable $failure) {
    // Le SQLSTATE seul (ou la classe, hors base) : le message du pilote
    // recopie l'instruction.
    echo 'failed '.($failure instanceof DriverException ? ($failure->getSQLState() ?? 'inconnu') : $failure::class)."\n";
    exit(3);
}

$connectionAudit = 0;
$causedAudit = 0;
$clientAudit = 0;
foreach ($auditHandler->getRecords() as $record) {
    $event = $record->context['event'] ?? null;
    if ('mcp-connection-revoked' === $event) {
        ++$connectionAudit;
        // La cause du journal est un contrat (D-B) : la valeur littérale, pas la
        // constante de la classe, pour qu'un renommage ne passe pas en silence.
        if ('client-removed' === ($record->context['cause'] ?? null)) {
            ++$causedAudit;
        }
    }
    if ('mcp-client-removed' === $event) {
        ++$clientAudit;
    }
}

$retried = 0;
foreach ($allChannelsHandler->getRecords() as $record) {
    if ('deadlock-retried' === ($record->context['event'] ?? null)) {
        ++$retried;
    }
}

echo "done\n";
echo 'removed '.$removed."\n";
echo 'connectionaudit '.$connectionAudit."\n";
echo 'causedaudit '.$causedAudit."\n";
echo 'clientaudit '.$clientAudit."\n";
echo 'retried '.$retried."\n";

exit(0);
