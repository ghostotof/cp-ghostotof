<?php

declare(strict_types=1);

/*
 * Processus fils de McpConnectionPurgeLockOrderTest (#649, lot C des
 * relectures) : lance la **vraie** purge quotidienne des connexions MCP
 * (McpConnectionPurgerInterface, celle d'`app:mcp:purge`) pendant que le test
 * tient, dans la transaction de son détenteur, une ligne que la purge devra
 * prendre —
 * une nuit où la purge croise une suppression de compte en cascade, ou une
 * révocation au point de révocation.
 *
 * Usage : php purge-mcp-connections.php <fichier-barrière>
 *
 * Aucun secret : la purge ne lit ni jeton ni code, la rétention est celle de
 * la configuration.
 *
 * Déroulé :
 *  1. démarre le kernel de test, ouvre sa connexion PostgreSQL et borne ses
 *     instructions à 3 s (`statement_timeout`, le filet des tests de
 *     concurrence : une session de console n'a pas de `lock_timeout`) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. purge, avec la rétention de la configuration, comme la commande ;
 *  4. écrit « purged <connexions> <codes> <accès> <rafraîchissements> » (les
 *     quatre compteurs de suppression) et sort en 0 ; « failed <SQLSTATE> »
 *     et sort en 3 si la base l'a refusée (un interblocage, 40P01, ou le
 *     filet, 57014 ; la classe pour toute autre exception) — jamais le
 *     message du pilote ; sort en ChildPhpProcess::SETUP_FAILED s'il n'a pas
 *     pu se mettre en place.
 */

use App\Mcp\Connection\Application\McpConnectionPurgerInterface;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath] = $arguments + [null, null];
if (!\is_string($barrierPath)) {
    fwrite(\STDERR, "Usage : php purge-mcp-connections.php <fichier-barrière>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$purger = TestServiceLocator::locate(McpConnectionPurgerInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
$clock = TestServiceLocator::locate(ClockInterface::class);
$parameters = TestServiceLocator::locate('parameter_bag');
if (!$purger instanceof McpConnectionPurgerInterface || !$entityManager instanceof EntityManagerInterface || !$clock instanceof ClockInterface || !$parameters instanceof ParameterBagInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

// La rétention de la configuration, celle que la commande lit par défaut
// (une chaîne, phpstan-symfony le sait du conteneur compilé).
$retention = $parameters->get('app.mcp.revoked_connection_retention');

$connection = $entityManager->getConnection();
$connection->executeStatement("SET statement_timeout = '3s'");
$pid = $connection->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

try {
    $result = $purger->purge(RetentionPeriod::fromString($retention, $clock->now()));
} catch (Throwable $failure) {
    // Le SQLSTATE seul (ou la classe, hors base) : le message du pilote
    // recopie l'instruction.
    echo 'failed '.($failure instanceof DriverException ? ($failure->getSQLState() ?? 'inconnu') : $failure::class)."\n";
    exit(3);
}

echo \sprintf("purged %d %d %d %d\n", $result->deletedConnections, $result->deletedAuthorizationCodes, $result->deletedAccessTokens, $result->deletedRefreshTokens);

exit(0);
