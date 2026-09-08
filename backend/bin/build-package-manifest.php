#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Infrastructure\Manifest\LockFilePackageManifestBuilder;

/*
 * Relève le périmètre déployé pendant la construction de l'image.
 *
 * Volontairement **sans kernel Symfony**, contrairement à la commande
 * `app:watch:build-manifest` qui rend le même service en développement : au
 * build, aucun secret n'est disponible — ni APP_SECRET, ni DATABASE_URL — et
 * démarrer l'application pour lire deux fichiers JSON reviendrait à exiger une
 * configuration complète pour une tâche qui n'en a aucun besoin. Le
 * constructeur de manifeste n'ayant aucune dépendance, l'autoloader suffit.
 *
 * C'est ici, et seulement ici, que les deux fichiers de verrouillage
 * coexistent : le conteneur applicatif ne voit jamais le dépôt frontend. Le
 * manifeste produit voyage ensuite dans l'image, ce qui permet à l'analyse
 * quotidienne de porter sur le périmètre réel du déploiement, front compris.
 *
 * Usage : php bin/build-package-manifest.php [chemin/vers/package-lock.json]
 */

require dirname(__DIR__).'/vendor/autoload.php';

$projectDir = dirname(__DIR__);
$npmLockPath = $argv[1] ?? $projectDir.'/../frontend/package-lock.json';

$builder = new LockFilePackageManifestBuilder(
    $projectDir.'/composer.lock',
    $npmLockPath,
    $projectDir.'/config/watch/package-manifest.json',
);

$manifest = $builder->build(new DateTimeImmutable());

$byEcosystem = [];
foreach ($manifest->packages as $package) {
    $byEcosystem[$package->ecosystem] = ($byEcosystem[$package->ecosystem] ?? 0) + 1;
}

foreach ([PackageCoordinates::ECOSYSTEM_PACKAGIST, PackageCoordinates::ECOSYSTEM_NPM] as $ecosystem) {
    printf("  %-12s %d paquets%s", $ecosystem, $byEcosystem[$ecosystem] ?? 0, \PHP_EOL);
}

if ([] === $manifest->packages) {
    // Ne pas faire échouer le build : un manifeste vide reste exploitable, et
    // l'application saura dire qu'elle n'a rien analysé. Mais le signaler, car
    // en production c'est le symptôme de fichiers de verrouillage introuvables.
    fwrite(\STDERR, 'Attention : aucun paquet relevé.'.\PHP_EOL);
}

printf('%d paquets relevés.%s', \count($manifest->packages), \PHP_EOL);
