<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Le noyau de l'application dans un environnement donné, compilé pour lire
 * les définitions de ses services de sécurité avant que les passes de retrait
 * ne les inlinent (issue #698) — entre {@see EnvironmentConfigKernel}, qui ne
 * compile pas et ne voit que la configuration déclarée, et
 * {@see EnvironmentRoutesKernel}, qui compile jusqu'au bout. L'instantané est
 * celui de {@see SecurityServicesSnapshotPass}.
 *
 * Le conteneur n'est jamais écrit sur le disque : seul le ContainerBuilder
 * est compilé, en mémoire. Un fichier de configuration de plus peut être
 * chargé après les autres, comme {@see ExtraConfigKernel} le fait en `test` :
 * c'est ainsi que la garde prouve qu'elle voit une surcharge propre à `prod`
 * sans en ajouter une à la vraie configuration.
 *
 * Hors débogage, comme la production et les autres noyaux de parité : une
 * définition qui dépendrait de `%kernel.debug%` échapperait à la comparaison,
 * limite acceptée (aucune).
 */
final class EnvironmentServicesKernel extends Kernel
{
    private readonly SecurityServicesSnapshotPass $snapshotPass;

    public function __construct(string $environment, private readonly ?string $extraConfigFile = null)
    {
        $this->snapshotPass = new SecurityServicesSnapshotPass();
        parent::__construct($environment, false);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        if (null !== $this->extraConfigFile) {
            $loader->load($this->extraConfigFile);
        }
    }

    /**
     * À part du cache de chaque environnement, et d'un fichier ajouté à
     * l'autre.
     */
    public function getCacheDir(): string
    {
        return RepositoryFiles::backend(\sprintf('var/cache/environment_services/%s-%s', $this->environment, hash('xxh3', $this->extraConfigFile ?? '')));
    }

    public function securityServices(): SecurityServicesSnapshot
    {
        // Ce que boot() ferait avant d'en venir au conteneur : les bundles de
        // l'environnement, qui enregistrent les extensions.
        $this->initializeBundles();
        $this->buildContainer()->compile();

        return $this->snapshotPass->snapshot();
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // La plus basse des priorités : après toute autre passe de la phase,
        // RegisterListenersPass comprise. SecurityServicesSnapshotPass refuse
        // de prendre l'instantané si une autre la suit encore.
        $container->addCompilerPass($this->snapshotPass, PassConfig::TYPE_BEFORE_REMOVING, \PHP_INT_MIN);
    }
}
