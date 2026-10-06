<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;

/**
 * Le noyau de l'application dans un environnement donné, pour lire ce que sa
 * configuration déclare — sans compiler le conteneur (issue #357).
 *
 * Les chargeurs de Symfony font le tri par environnement (`when@<env>`,
 * config/packages/<env>/, services_<env>.*, en YAML comme en PHP) ; le
 * ContainerBuilder qu'ils remplissent garde, avant compilation, les fragments
 * bruts de chaque extension. Le projet lu peut être une fixture : son
 * config/bundles.php doit alors rendre ceux de l'application.
 */
final class EnvironmentConfigKernel extends Kernel
{
    public function __construct(string $environment, private readonly ?string $projectDirectory = null)
    {
        parent::__construct($environment, false);
    }

    public function getProjectDir(): string
    {
        return $this->projectDirectory ?? parent::getProjectDir();
    }

    /**
     * Sous var/ de l'application, jamais sous la fixture, et à part du cache
     * de chaque environnement.
     */
    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2).'/var/cache/environment_config/'.$this->environment.'-'.hash('xxh3', $this->getProjectDir());
    }

    /**
     * @return list<array<mixed>> les fragments déclarés pour l'extension, dans leur ordre de chargement
     */
    public function declaredConfig(string $extension): array
    {
        // Ce que boot() ferait avant d'en venir au conteneur : les bundles de
        // l'environnement, qui enregistrent les extensions.
        $this->initializeBundles();

        /** @var list<array<mixed>> */
        return $this->buildContainer()->getExtensionConfig($extension);
    }
}
