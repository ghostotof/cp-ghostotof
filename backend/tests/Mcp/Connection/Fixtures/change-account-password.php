<?php

declare(strict_types=1);

/*
 * Processus fils de AccountWideRevocationDeadlockTest (#650, R4, F1) : un
 * super-administrateur change le mot de passe d'un compte — le vrai
 * CpgUserAdministrator::changePassword(), qui verrouille le compte puis révoque
 * toutes ses connexions MCP actives dans la même transaction — pendant que le
 * test tient une connexion du compte et qu'un échange de code croise ses
 * verrous.
 *
 * Usage : php change-account-password.php <fichier-barrière> <uuid du compte> [<deadlock_timeout>]
 *
 * `deadlock_timeout` (facultatif, `100ms`, `2s`…) : le délai après lequel cette session
 * cherche un cycle de verrous — la plus courte des deux sessions croisées le trouve
 * la première et s'annule elle-même (`40P01`). C'est ce qui désigne la victime d'un
 * interblocage, sans quoi PostgreSQL la choisit au hasard des minuteries.
 *
 * Aucun secret en argument ni en sortie : le nouveau mot de passe est tiré au
 * hasard dans ce processus et n'est jamais écrit (le test ne compare que
 * l'empreinte stockée, avant et après).
 *
 * Déroulé :
 *  1. démarre le kernel de test, ouvre sa connexion PostgreSQL et borne ses
 *     instructions à 8 s (`statement_timeout`, le filet : plus long que celui
 *     de l'échange, la révocation attend le temps que le test démarre l'autre
 *     fils ; une attente que rien ne relâcherait échoue en 57014 au lieu de
 *     suspendre la suite) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. change le mot de passe, comme le fait la route du backoffice ;
 *  4. écrit « done » et sort en 0 si le changement a abouti, puis
 *     « audit <n> » : le nombre de lignes `mcp-connection-revoked` que CET appel
 *     a écrites (le canal `security_audit` de ce processus),
 *     « passwordaudit <n> » (`password-changed`) et « retried <n> » (S3 : le
 *     nombre d'interblocages que la relance unique a absorbés, lu sur la sonde de
 *     tous les canaux) ; « failed <SQLSTATE> » et sort
 *     en 3 si la base l'a refusé (un interblocage resté après la relance, 40P01 ;
 *     la classe pour toute autre exception) — jamais le message du pilote ; sort en
 *     ChildPhpProcess::SETUP_FAILED s'il n'a pas pu se mettre en place.
 */

use App\Security\User\Application\CpgUserAdministratorInterface;
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
[, $barrierPath, $accountId, $deadlockTimeout] = $arguments + [null, null, null, null];
if (!\is_string($barrierPath) || !\is_string($accountId) || !Uuid::isValid($accountId) || (null !== $deadlockTimeout && (!\is_string($deadlockTimeout) || 1 !== preg_match('/^\d+(ms|s)\z/', $deadlockTimeout)))) {
    fwrite(\STDERR, "Usage : php change-account-password.php <fichier-barrière> <uuid du compte> [<deadlock_timeout>]\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$administrator = TestServiceLocator::locate(CpgUserAdministratorInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
$auditLogger = TestServiceLocator::locate('monolog.logger.security_audit');
if (!$administrator instanceof CpgUserAdministratorInterface || !$entityManager instanceof EntityManagerInterface || !$auditLogger instanceof Logger) {
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

// La sonde de tous les canaux (niveau `debug`, la même instance pour chaque
// logger) : ce que la relance a journalisé, hors du canal d'audit.
$allChannelsHandler = null;
foreach ($auditLogger->getHandlers() as $handler) {
    if ($handler instanceof TestHandler && Level::Debug === $handler->getLevel()) {
        $allChannelsHandler = $handler;
    }
}
if (null === $allChannelsHandler) {
    fwrite(\STDERR, "Aucune sonde de tous les canaux.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

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
    $administrator->changePassword(Uuid::fromString($accountId), bin2hex(random_bytes(16)));
} catch (Throwable $failure) {
    // Le SQLSTATE seul (ou la classe, hors base) : le message du pilote
    // recopie l'instruction.
    echo 'failed '.($failure instanceof DriverException ? ($failure->getSQLState() ?? 'inconnu') : $failure::class)."\n";
    exit(3);
}

$revoked = 0;
$passwordChanged = 0;
foreach ($auditHandler->getRecords() as $record) {
    $event = $record->context['event'] ?? null;
    if ('mcp-connection-revoked' === $event) {
        ++$revoked;
    }
    if ('password-changed' === $event) {
        ++$passwordChanged;
    }
}

$retried = 0;
foreach ($allChannelsHandler->getRecords() as $record) {
    if ('deadlock-retried' === ($record->context['event'] ?? null)) {
        ++$retried;
    }
}

echo "done\n";
echo 'audit '.$revoked."\n";
echo 'passwordaudit '.$passwordChanged."\n";
echo 'retried '.$retried."\n";

exit(0);
