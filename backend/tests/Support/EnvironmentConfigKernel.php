<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * Le noyau de l'application dans un environnement donné, pour lire ce que sa
 * configuration déclare — sans compiler le conteneur (issue #357).
 *
 * Les chargeurs de Symfony font le tri par environnement (`when@<env>`,
 * config/packages/<env>/, services_<env>.*, en YAML comme en PHP) ; le
 * ContainerBuilder qu'ils remplissent garde, avant compilation, les fragments
 * de chaque extension, que la configuration de l'extension sait normaliser. Le projet lu peut être une fixture : son
 * config/bundles.php doit alors rendre ceux de l'application.
 */
final class EnvironmentConfigKernel extends Kernel
{
    private ?ContainerBuilder $builder = null;

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
     * Une clé de premier niveau d'une extension, telle que ses fragments la
     * déclarent dans cet environnement : chacun normalisé, puis fusionnés dans
     * l'ordre de chargement, par le nœud de configuration de l'extension
     * elle-même — comme le composant Config avant de charger l'extension. La
     * forme liste (`- { class: … }`) et les clés à tirets y sont donc ramenées
     * à la forme canonique. Ni validée ni complétée de ses défauts : seul ce
     * qui est déclaré compte, et une valeur paramétrée reste telle quelle.
     *
     * @return array<array-key, mixed>
     */
    public function declaredMapping(string $extension, string $key): array
    {
        $builder = $this->builder();
        $tree = $this->configurationTree($builder, $extension);

        $merged = [];
        foreach ($builder->getExtensionConfig($extension) as $fragment) {
            $declared = array_intersect_key($fragment, [$key => true, str_replace('_', '-', $key) => true]);
            if ([] !== $declared) {
                $merged = $tree->merge($merged, $tree->normalize($declared));
            }
        }
        $value = \is_array($merged) ? ($merged[$key] ?? []) : [];

        return \is_array($value) ? $value : [];
    }

    /**
     * Construit une fois par noyau : chaque chargement de l'application
     * importe services.yaml, donc parcourt et charge tout src/.
     */
    private function builder(): ContainerBuilder
    {
        if (null === $this->builder) {
            // Ce que boot() ferait avant d'en venir au conteneur : les bundles
            // de l'environnement, qui enregistrent les extensions.
            $this->initializeBundles();
            $this->builder = $this->buildContainer();
        }

        return $this->builder;
    }

    private function configurationTree(ContainerBuilder $builder, string $extension): NodeInterface
    {
        $loaded = $builder->getExtension($extension);
        $configuration = $loaded instanceof ConfigurationExtensionInterface ? $loaded->getConfiguration([], $builder) : null;
        if (null === $configuration) {
            throw new \LogicException(\sprintf('L\'extension « %s » n\'expose pas sa configuration.', $extension));
        }

        return $configuration->getConfigTreeBuilder()->buildTree();
    }
}
