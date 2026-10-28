<?php

declare(strict_types=1);

/*
 * Processus fils de TokenEndpointConcurrencyTest (#649) : présente au point de
 * jeton le corps de formulaire lu sur son entrée standard — un rafraîchissement
 * ou l'échange d'un code — pendant que le test présente le même jeton ou le
 * même code dans sa propre transaction, verrou de la connexion tenu : deux
 * workers PHP-FPM servant deux requêtes simultanées d'un même client.
 *
 * Usage : php present-at-token-endpoint.php <fichier-barrière> [<deadlock_timeout>] < <corps application/x-www-form-urlencoded>
 *
 * `deadlock_timeout` (facultatif, `100ms`, `2s`…) : le délai après lequel cette session
 * cherche un cycle de verrous ; la plus courte de deux sessions croisées le trouve
 * la première et s'annule elle-même (`40P01`) — la victime d'un interblocage que le
 * test veut désigner (AccountWideRevocationDeadlockTest).
 *
 * Le corps vient de l'entrée standard, jamais de la ligne de commande : il
 * porte le jeton ou le code, et la ligne de commande se recopie dans les
 * messages d'échec du test (`Process::getCommandLine()`), comme dans un `ps`.
 *
 * Déroulé :
 *  1. démarre le kernel de test, ouvre sa connexion PostgreSQL et borne ses
 *     instructions à 3 s (`statement_timeout`, le filet des tests de
 *     concurrence : une session de console n'a pas de `lock_timeout`, et une
 *     attente que rien ne relâcherait suspendrait la suite au lieu d'échouer
 *     en 57014) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. présente le corps à TokenEndpointInterface, comme le contrôleur ;
 *  4. écrit « issued <statut> » et sort en 0 si league a émis des jetons —
 *     jamais le corps de la réponse, qui les porte —, « refused <statut> <JSON
 *     du refus> » (RFC 6749 §5.2 : `error`, `hint`, sans jeton) et sort en 1
 *     sur un refus OAuth, « failed <SQLSTATE> » et sort en 3 si la base l'a
 *     refusé (un interblocage, 40P01 ; jamais le message du pilote, qui
 *     recopie l'instruction), sort en ChildPhpProcess::SETUP_FAILED s'il n'a
 *     pas pu se mettre en place.
 */

use App\Mcp\Connection\Application\TokenEndpointInterface;
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
[, $barrierPath, $deadlockTimeout] = $arguments + [null, null, null];
if (!\is_string($barrierPath) || (null !== $deadlockTimeout && (!\is_string($deadlockTimeout) || 1 !== preg_match('/^\d+(ms|s)\z/', $deadlockTimeout)))) {
    fwrite(\STDERR, "Usage : php present-at-token-endpoint.php <fichier-barrière> < <corps du formulaire>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$body = stream_get_contents(\STDIN);
if (!\is_string($body) || '' === $body) {
    fwrite(\STDERR, "Aucun corps de formulaire sur l'entrée standard.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$endpoint = TestServiceLocator::locate(TokenEndpointInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$endpoint instanceof TokenEndpointInterface || !$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$connection = $entityManager->getConnection();
$connection->executeStatement("SET statement_timeout = '3s'");
if (\is_string($deadlockTimeout)) {
    $connection->executeStatement('SET deadlock_timeout = '.$connection->quote($deadlockTimeout));
}
$pid = $connection->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

$request = new ServerRequest('POST', 'http://localhost/api/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], $body);

try {
    $response = $endpoint->respond($request, new Response());
} catch (OAuthServerException $refusal) {
    // Le refus tel que le contrôleur le rendrait : le JSON de league, qui ne
    // cite ni le jeton ni le code présentés.
    $rendered = $refusal->generateHttpResponse(new Response());
    echo 'refused '.$rendered->getStatusCode().' '.$rendered->getBody()."\n";
    exit(1);
} catch (DriverException $failure) {
    echo 'failed '.($failure->getSQLState() ?? 'inconnu')."\n";
    exit(3);
}

echo 'issued '.$response->getStatusCode()."\n";

exit(0);
