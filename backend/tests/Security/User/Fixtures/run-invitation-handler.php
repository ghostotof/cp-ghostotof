<?php

declare(strict_types=1);

/*
 * Processus fils de SendAccountInvitationConcurrencyTest (issue #435) : traite
 * un message d'invitation pendant que le test en tient un autre, du même
 * compte, en suspens dans sa transaction — un `messenger:consume failed` lancé
 * à la main pendant que le worker tourne.
 *
 * Usage : php run-invitation-handler.php <fichier-barrière> <identifiant du compte> <génération>
 *
 * Déroulé :
 *  1. démarre le kernel de test et ouvre sa connexion PostgreSQL ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. exécute le handler sur le message ;
 *  4. code de sortie 0 si le handler a abouti, 2 si le fils n'a pas pu se
 *     mettre en place.
 */

use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\ORM\EntityManagerInterface;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $userId, $generation] = $arguments + [null, null, null, null];
if (!\is_string($barrierPath) || !\is_string($userId) || !\is_string($generation) || !ctype_digit($generation)) {
    fwrite(\STDERR, "Usage : php run-invitation-handler.php <fichier-barrière> <identifiant du compte> <génération>\n");
    exit(2);
}

$handler = TestServiceLocator::locate(SendAccountInvitationHandler::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$handler instanceof SendAccountInvitationHandler || !$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(2);
}

$pid = $entityManager->getConnection()->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(2);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

$handler(new SendAccountInvitationMessage($userId, 'fr', (int) $generation));

exit(0);
