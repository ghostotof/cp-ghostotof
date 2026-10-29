<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le noyau de l'application dans un environnement donné, compilé pour lire
 * les définitions de ses services de sécurité avant que les passes de retrait
 * ne les inlinent (issue #698) — entre {@see EnvironmentConfigKernel}, qui ne
 * compile pas et ne voit que la configuration déclarée, et
 * {@see EnvironmentRoutesKernel}, qui compile jusqu'au bout. L'instantané est
 * celui de {@see SecurityServicesSnapshotPass}.
 *
 * Seul le ContainerBuilder est compilé, en mémoire : le conteneur n'est pas
 * écrit. Le noyau crée tout de même ses répertoires de cache et de build ;
 * ils sont propres au processus, comme ceux d'EnvironmentRoutesKernel, et
 * supprimés après lecture. Des fichiers de configuration de plus peuvent être
 * chargés après les autres, comme {@see ExtraConfigKernel} le fait en `test`
 * : c'est ainsi que la garde prouve qu'elle voit une surcharge propre à
 * `prod` sans en ajouter une à la vraie configuration.
 *
 * La configuration est chargée sous l'environnement lu
 * ({@see ProcessEnvironment}), hors débogage, comme la production et les
 * autres noyaux de parité : une définition qui dépendrait de `%kernel.debug%`
 * échapperait à la comparaison, limite acceptée (aucune ; préproduction et
 * production tournent avec APP_DEBUG=0).
 */
final class EnvironmentServicesKernel extends Kernel
{
    private readonly SecurityServicesSnapshotPass $snapshotPass;

    /**
     * @param list<string>                $extraConfigFiles
     * @param list<CompilerPassInterface> $extraPasses      des passes de lecture ({@see DeprecatedReferencesOfApiPlatform}), posées
     *                                                      en `TYPE_BEFORE_OPTIMIZATION` avec la priorité `PHP_INT_MIN` : en fin de
     *                                                      cette phase, après les autres passes qui ajoutent des références, et
     *                                                      avant l'optimisation qui remplace un alias par sa cible ; aucune par défaut
     */
    public function __construct(string $environment, private readonly array $extraConfigFiles, private readonly array $extraPasses = [])
    {
        $this->snapshotPass = new SecurityServicesSnapshotPass();
        parent::__construct($environment, false);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        foreach ($this->extraConfigFiles as $file) {
            $loader->load($file);
        }
    }

    public function getCacheDir(): string
    {
        return RepositoryFiles::backend(\sprintf('var/cache/environment_services/%s-%d', $this->environment, getmypid()));
    }

    public function securityServices(): SecurityServicesSnapshot
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->getCacheDir());
        try {
            return ProcessEnvironment::whileRunningAs($this->environment, function (): SecurityServicesSnapshot {
                // Ce que boot() ferait avant d'en venir au conteneur : les
                // bundles de l'environnement, qui enregistrent les extensions.
                $this->initializeBundles();
                $this->buildContainer()->compile();

                return $this->snapshotPass->snapshot();
            });
        } finally {
            $filesystem->remove($this->getCacheDir());
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // En fin de phase `TYPE_BEFORE_OPTIMIZATION` (`PHP_INT_MIN`), donc avant
        // l'optimisation, qui remplace les références à un alias par sa cible.
        foreach ($this->extraPasses as $pass) {
            $container->addCompilerPass($pass, PassConfig::TYPE_BEFORE_OPTIMIZATION, \PHP_INT_MIN);
        }
        // La plus basse des priorités : après toute autre passe de la phase,
        // RegisterListenersPass comprise. SecurityServicesSnapshotPass refuse
        // de prendre l'instantané si une autre la suit encore.
        $container->addCompilerPass($this->snapshotPass, PassConfig::TYPE_BEFORE_REMOVING, \PHP_INT_MIN);
    }
}
