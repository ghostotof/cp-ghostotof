<?php

declare(strict_types=1);

/*
 * Processus fils de ContributionOrderConcurrencyTest (issue #499) : supprime
 * une contribution pendant que le test tient son `PUT` en suspens.
 *
 * Usage : php delete-contribution.php <fichier-barrière> <id>
 *
 * Déroulé :
 *  1. démarre le kernel de test, ouvre sa connexion PostgreSQL et **charge
 *     l'entrée**, comme le provider d'un `DELETE /api/backoffice/…/{id}`
 *     avant le processor : tout ce qui est lent se fait avant la barrière ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion — celle qui attendra le verrou —, que le test
 *     relâche au point d'entrelacement voulu ;
 *  3. supprime l'entrée, puis écrit « titre-supprimé: <titre> », le titre que
 *     portait l'instance supprimée.
 *     Sous le verrou, elle a été relue (#402) : c'est le titre que la base
 *     portait au moment de la suppression. Hors du verrou, c'est celui du
 *     chargement ;
 *  4. code de sortie 0 si la suppression a réussi, ChildPhpProcess::SETUP_FAILED si le fils n'a pas pu se
 *     mettre en place.
 */

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

require \dirname(__DIR__, 3).'/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $id] = $arguments + [null, null, null];
if (!\is_string($barrierPath) || !\is_string($id)) {
    fwrite(\STDERR, "Usage : php delete-contribution.php <fichier-barrière> <id>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$administrator = TestServiceLocator::locate(ContributionAdministratorInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$administrator instanceof ContributionAdministratorInterface || !$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

// Chargée avant la barrière, comme par le provider : la suppression la
// retrouve ensuite dans la carte d'identité.
$contribution = $entityManager->find(Contribution::class, Uuid::fromString($id));
if (!$contribution instanceof Contribution) {
    fwrite(\STDERR, \sprintf("Contribution %s introuvable avant la barrière.\n", $id));
    exit(ChildPhpProcess::SETUP_FAILED);
}

// Son pid permet au test de reconnaître l'attente de verrou de ce processus-ci.
$pid = $entityManager->getConnection()->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

$administrator->delete($contribution->getId());

echo 'titre-supprimé: '.$contribution->getTitle()."\n";

exit(0);
