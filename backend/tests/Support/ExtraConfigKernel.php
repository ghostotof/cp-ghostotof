<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

/**
 * Le noyau de l'application, en `test`, avec un fichier de configuration de
 * plus chargé après les autres (issue #357).
 *
 * Sert à prouver qu'un garde-fou lit bien la configuration compilée : une
 * entrée déclarée hors des fichiers attendus doit y apparaître, sans qu'il
 * faille en ajouter une à la vraie configuration. Son conteneur est compilé
 * dans son propre répertoire de cache, pour ne jamais se mêler à celui de
 * l'environnement de test.
 */
final class ExtraConfigKernel extends Kernel
{
    public function __construct(private readonly string $extraConfigFile)
    {
        parent::__construct('test', true);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load($this->extraConfigFile);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/test_extra_config/'.hash('xxh3', $this->extraConfigFile);
    }
}
