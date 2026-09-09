#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Portfolio\Watch\Infrastructure\Manifest\DeployedVersionsBuilder;

/*
 * Relève les versions des composants déployés pendant la construction de
 * l'image, depuis les fichiers qui font foi.
 *
 * Même parti pris que build-package-manifest.php, et pour les mêmes raisons :
 * **sans kernel Symfony**. Au build, ni APP_SECRET ni DATABASE_URL n'existent,
 * et démarrer l'application pour lire quatre fichiers texte exigerait une
 * configuration complète sans aucun besoin.
 *
 * C'est ici, et seulement ici, que la racine du dépôt est accessible : le
 * conteneur applicatif ne voit que backend/. Le fichier produit voyage ensuite
 * dans l'image, ce qui garantit qu'il décrit exactement ce que celle-ci
 * déploie.
 *
 * Les tags arrivent par variables d'environnement plutôt que par copie des
 * fichiers sources, pour deux raisons distinctes : `k8s/` est délibérément
 * exclu du contexte de build — l'image n'a pas à embarquer les manifestes de
 * déploiement — et copier `.env` créerait une couche qui le conserverait même
 * supprimé ensuite. Le Makefile extrait donc les tags et les passe en
 * arguments de build.
 *
 * Usage : php bin/build-deployed-versions.php [racine-du-dépôt]
 * Variables : POSTGRES_TAG, RABBITMQ_TAG, NGINX_TAG, NODE_TAG
 */

require dirname(__DIR__).'/vendor/autoload.php';

$projectDir = dirname(__DIR__);
$repositoryRoot = $argv[1] ?? $projectDir.'/..';

$overrides = [];
foreach (['postgresql' => 'POSTGRES_TAG', 'rabbitmq' => 'RABBITMQ_TAG', 'nginx' => 'NGINX_TAG', 'nodejs' => 'NODE_TAG'] as $slug => $variable) {
    $value = getenv($variable);

    if (\is_string($value) && '' !== $value) {
        $overrides[$slug] = $value;
    }
}

$builder = new DeployedVersionsBuilder(
    $repositoryRoot,
    $repositoryRoot.'/frontend/package-lock.json',
    $projectDir.'/config/watch/deployed-versions.json',
    $overrides,
);

$versions = $builder->build(new DateTimeImmutable());

if ([] === $versions) {
    // Pas une erreur fatale : l'image reste utilisable, la page affichera
    // simplement ces produits sans version. Mais le signaler évite qu'un
    // déplacement de fichier passe inaperçu pendant des semaines.
    fwrite(STDERR, "Aucune version relevée — vérifier les chemins des manifestes.\n");

    exit(0);
}

foreach ($versions as $slug => $version) {
    printf("  %-12s %s\n", $slug, $version);
}

printf("%d version(s) relevée(s).\n", \count($versions));
