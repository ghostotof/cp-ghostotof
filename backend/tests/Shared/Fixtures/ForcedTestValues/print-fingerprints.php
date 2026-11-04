<?php

declare(strict_types=1);

/*
 * Processus fils de ForcedTestValuesProbeTest (#748) : démarre le noyau de
 * test comme un fils de test de concurrence, puis écrit en JSON l'empreinte de
 * chaque valeur forcée telle que ce noyau la lit, jamais la valeur.
 *
 * Usage : php print-fingerprints.php
 *
 * Code de sortie 0, ou ChildPhpProcess::SETUP_FAILED si le fils n'a pas pu se
 * mettre en place.
 */

use App\Tests\Shared\ForcedTestValuesProbeTest;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\TestServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;

require \dirname(__DIR__, 3).'/bootstrap.php';

$kernel = TestServiceLocator::locate('kernel');
if (!$kernel instanceof KernelInterface) {
    fwrite(\STDERR, "Noyau introuvable dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

echo json_encode(ForcedTestValuesProbeTest::fingerprints($kernel->getEnvironment(), $kernel->getContainer()), \JSON_THROW_ON_ERROR);
