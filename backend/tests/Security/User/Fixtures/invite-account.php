<?php

declare(strict_types=1);

/*
 * Processus fils de InviteUsernameConcurrencyTest (issue #364) : invite une
 * adresse pendant que le test en invite une autre, de même partie locale —
 * deux workers PHP-FPM qui servent deux invitations simultanées.
 *
 * Usage : php invite-account.php <fichier-barrière> <adresse e-mail>
 *
 * Déroulé :
 *  1. démarre le kernel de test et ouvre sa connexion PostgreSQL ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion ;
 *  3. invite l'adresse ;
 *  4. écrit « username <identifiant> » et sort en 0 si l'invitation a abouti,
 *     « refused <classe de l'exception> » et sort en 1 si elle a été refusée,
 *     sort en ChildPhpProcess::SETUP_FAILED si le fils n'a pas pu se mettre en
 *     place.
 */

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\ORM\EntityManagerInterface;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $email] = $arguments + [null, null, null];
if (!\is_string($barrierPath) || !\is_string($email) || '' === $email) {
    fwrite(\STDERR, "Usage : php invite-account.php <fichier-barrière> <adresse e-mail>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$inviter = TestServiceLocator::locate(CpgUserInviterInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$inviter instanceof CpgUserInviterInterface || !$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$pid = $entityManager->getConnection()->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

try {
    $user = $inviter->invite($email, Locale::FR);
} catch (Throwable $refusal) {
    // La classe seule : le message d'une exception de la base peut citer la
    // ligne en conflit, que le test recopierait dans son message d'échec.
    echo 'refused '.$refusal::class."\n";
    exit(1);
}

echo 'username '.$user->getUsername()."\n";

exit(0);
