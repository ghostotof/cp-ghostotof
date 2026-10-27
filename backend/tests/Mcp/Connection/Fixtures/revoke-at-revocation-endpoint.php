<?php

declare(strict_types=1);

/*
 * Processus fils de RevocationEndpointConcurrencyTest (#649) : présente au
 * point de révocation le corps de formulaire lu sur son entrée standard — un
 * jeton de rafraîchissement et son `client_id` — pendant que le test
 * rafraîchit ce même jeton au point de jeton, verrou de la connexion tenu :
 * une personne qui révoque un jeton au moment où un autre worker PHP-FPM le
 * fait tourner.
 *
 * Usage : php revoke-at-revocation-endpoint.php <fichier-barrière> < <corps application/x-www-form-urlencoded>
 *
 * Le corps vient de l'entrée standard, jamais de la ligne de commande : il
 * porte le jeton, et la ligne de commande se recopie dans les messages d'échec
 * du test (`Process::getCommandLine()`), comme dans un `ps`.
 *
 * Déroulé :
 *  1. démarre le kernel de test, ouvre sa connexion PostgreSQL et borne ses
 *     instructions à 3 s (`statement_timeout`, le filet des tests de
 *     concurrence : une session de console n'a pas de `lock_timeout`) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. présente le corps à RevocationEndpointInterface, comme le contrôleur ;
 *  4. écrit « revoked » et sort en 0 si la révocation a abouti ; « refused
 *     <statut> <JSON du refus> » et sort en 1 sur un refus OAuth ; « failed
 *     <SQLSTATE> » et sort en 3 si la base l'a refusée (un interblocage,
 *     40P01, ou le filet, 57014 ; la classe pour toute autre exception) —
 *     jamais le message du pilote, ni le corps ;
 *     sort en ChildPhpProcess::SETUP_FAILED s'il n'a pas pu se mettre en place.
 */

use App\Mcp\Connection\Application\RevocationEndpointInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath] = $arguments + [null, null];
if (!\is_string($barrierPath)) {
    fwrite(\STDERR, "Usage : php revoke-at-revocation-endpoint.php <fichier-barrière> < <corps du formulaire>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$body = stream_get_contents(\STDIN);
if (!\is_string($body) || '' === $body) {
    fwrite(\STDERR, "Aucun corps de formulaire sur l'entrée standard.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$endpoint = TestServiceLocator::locate(RevocationEndpointInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$endpoint instanceof RevocationEndpointInterface || !$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
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

$request = new ServerRequest('POST', 'http://localhost/api/oauth/revoke', ['Content-Type' => 'application/x-www-form-urlencoded'], $body);

try {
    $endpoint->revoke($request);
} catch (OAuthServerException $refusal) {
    $rendered = $refusal->generateHttpResponse(new Response());
    echo 'refused '.$rendered->getStatusCode().' '.$rendered->getBody()."\n";
    exit(1);
} catch (Throwable $failure) {
    // Le SQLSTATE seul (ou la classe, hors base) : le message du pilote
    // recopie l'instruction.
    echo 'failed '.($failure instanceof DriverException ? ($failure->getSQLState() ?? 'inconnu') : $failure::class)."\n";
    exit(3);
}

echo "revoked\n";

exit(0);
