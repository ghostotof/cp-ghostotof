<?php

declare(strict_types=1);

/*
 * Processus fils de ContributionOrderConcurrencyTest (issue #389) : crée une
 * contribution pendant que le test tient une écriture du même périmètre en
 * suspens.
 *
 * Usage : php create-contribution.php <fichier-barrière> <locale> [<groupe>]
 *
 * Déroulé :
 *  1. démarre le kernel de test et ouvre sa connexion PostgreSQL : tout ce
 *     qui est lent se fait avant la barrière ;
 *  2. attend la barrière (ProcessBarrier::awaitInChild()) en annonçant le pid
 *     de cette connexion — celle qui attendra le verrou —, que le test
 *     relâche au point d'entrelacement voulu ;
 *  3. crée la contribution (dans le groupe s'il est donné, en fin de
 *     périmètre sinon) et écrit sa position ;
 *  4. code de sortie 0 si la création a réussi, ChildPhpProcess::SETUP_FAILED si le fils n'a pas pu se
 *     mettre en place.
 */

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestServiceLocator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

require \dirname(__DIR__, 3).'/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
[, $barrierPath, $locale, $group] = $arguments + [null, null, null, null];
if (!\is_string($barrierPath) || !\is_string($locale)) {
    fwrite(\STDERR, "Usage : php create-contribution.php <fichier-barrière> <locale> [<groupe>]\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$administrator = TestServiceLocator::locate(ContributionAdministratorInterface::class);
$entityManager = TestServiceLocator::locate(EntityManagerInterface::class);
if (!$administrator instanceof ContributionAdministratorInterface || !$entityManager instanceof EntityManagerInterface) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

// Ouvre la connexion avant la barrière : la poignée de main ne doit pas
// retarder la création au-delà du point d'entrelacement. Son pid permet au
// test de reconnaître l'attente de verrou de ce processus-ci.
$pid = $entityManager->getConnection()->fetchOne('SELECT pg_backend_pid()');
if (!\is_int($pid)) {
    fwrite(\STDERR, "pg_backend_pid() n'a pas rendu d'entier.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

ProcessBarrier::awaitInChild($barrierPath, $pid);

$contribution = $administrator->create(
    Locale::from($locale),
    'Créée en concurrence',
    'Projet',
    'Référence',
    'https://example.com/concurrente',
    'Résumé',
    'Corps',
    \is_string($group) ? Uuid::fromString($group) : null,
);

echo $contribution->getPosition()."\n";

exit(0);
