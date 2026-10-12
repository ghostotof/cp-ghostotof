<?php

declare(strict_types=1);

/*
 * Processus fils de AccountAdministrationConcurrencyTest (issue #551) :
 * rétrograde ou supprime un compte au nom d'un autre, pendant que le test
 * fait l'opération croisée — deux workers PHP-FPM qui servent deux
 * super-administrateurs agissant l'un contre l'autre.
 *
 * Usage : php administer-account.php <fichier-barrière> <acteur> <cible> <demote|delete>
 *
 * Déroulé :
 *  1. démarre le kernel de test, charge l'acteur et la cible par leur
 *     identifiant de connexion (comme le fournisseur de sécurité et le
 *     provider API Platform le font pour une requête) ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de sa connexion PostgreSQL ;
 *  3. rétrograde ou supprime la cible, l'acteur agissant ;
 *  4. écrit « done » et sort en 0 si l'opération a abouti, « refused <classe
 *     de l'exception> » et sort en 1 si elle a été refusée, sort en
 *     ChildPhpProcess::SETUP_FAILED s'il n'a pas pu se mettre en place.
 */

use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Application\CpgUserRoleAdministratorInterface;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\ORM\EntityManagerInterface;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $actorUsername, $targetUsername, $action] = $arguments + [null, null, null, null, null];
if (!\is_string($barrierPath) || !\is_string($actorUsername) || !\is_string($targetUsername) || !\in_array($action, ['demote', 'delete'], true)) {
    fwrite(\STDERR, "Usage : php administer-account.php <fichier-barrière> <acteur> <cible> <demote|delete>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$repository = TestServiceLocator::locate(CpgUserRepositoryInterface::class);
$roleAdministrator = TestServiceLocator::locate(CpgUserRoleAdministratorInterface::class);
$administrator = TestServiceLocator::locate(CpgUserAdministratorInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$repository instanceof CpgUserRepositoryInterface || !$roleAdministrator instanceof CpgUserRoleAdministratorInterface
    || !$administrator instanceof CpgUserAdministratorInterface || !$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

// Chargés avant la barrière, comme pour une requête : l'acteur par le
// fournisseur de sécurité, la cible par le provider. Leur état en mémoire est
// celui d'avant l'opération croisée du test.
$actor = $repository->findOneByUsername($actorUsername);
$target = $repository->findOneByUsername($targetUsername);
if (null === $actor || null === $target) {
    fwrite(\STDERR, "Acteur ou cible introuvable.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$pid = $entityManager->getConnection()->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

try {
    if ('demote' === $action) {
        $roleAdministrator->setSuperAdmin($target->getId(), false, $actor);
    } else {
        $administrator->delete($target->getId(), $actor);
    }
} catch (Throwable $refusal) {
    // La classe seule : le message d'une exception de la base peut citer la
    // ligne en conflit, que le test recopierait dans son message d'échec.
    echo 'refused '.$refusal::class."\n";
    exit(1);
}

echo "done\n";

exit(0);
