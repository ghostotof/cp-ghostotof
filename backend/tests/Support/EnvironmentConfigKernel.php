<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Closure;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionMethod;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * Le noyau de l'application dans un environnement donné, pour lire ce que sa
 * configuration déclare — sans compiler le conteneur (issue #357). Les routes
 * qu'il sert, qui demandent le conteneur compilé, se lisent avec
 * {@see EnvironmentRoutesKernel} (issue #659) ; les définitions de ses
 * services de sécurité, après les passes et avant leur retrait, avec
 * {@see EnvironmentServicesKernel} (issue #698).
 *
 * Les chargeurs de Symfony font le tri par environnement (`when@<env>`,
 * config/packages/<env>/, services_<env>.*, en YAML comme en PHP) ; le
 * ContainerBuilder qu'ils remplissent garde, avant compilation, les fragments
 * de chaque extension, que la configuration de l'extension sait normaliser.
 * Le projet lu peut être une fixture : son config/bundles.php doit alors
 * rendre ceux de l'application. Hors débogage, comme la production : une
 * règle qui dépendrait de `%kernel.debug%` échapperait à la comparaison,
 * limite acceptée (aucune).
 */
final class EnvironmentConfigKernel extends Kernel
{
    private ?ContainerBuilder $builder = null;

    /**
     * Les environnements que le noyau admet, lus dans {@see Kernel::getAllowedEnvs()}
     * plutôt que recopiés, pour qu'un environnement ajouté soit vérifié par
     * chaque garde sans qu'on y pense. La méthode est privée : réflexion, ici
     * seulement (issue #491, quatre copies jusque-là).
     *
     * @return list<string>
     */
    public static function allowedEnvironments(): array
    {
        /** @var list<string> $allowed */
        $allowed = new ReflectionMethod(Kernel::class, 'getAllowedEnvs')->invoke(new Kernel('test', false));
        Assert::assertContains('prod', $allowed, 'Le noyau n\'admet plus `prod` : revoir les gardes qui lisent chaque environnement.');

        return $allowed;
    }

    /**
     * Les environnements qu'une garde de parité compare à `test`, la
     * référence : tous ceux que le noyau admet, sauf lui (issue #659, écrit
     * jusque-là dans chaque garde).
     *
     * @return list<string>
     */
    public static function comparedEnvironments(): array
    {
        return array_values(array_diff(self::allowedEnvironments(), ['test']));
    }

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
        return RepositoryFiles::backend('var/cache/environment_config/'.$this->environment.'-'.hash('xxh3', $this->getProjectDir()));
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
        $value = $this->mergedFragments(
            $extension,
            static fn (array $fragment): array => array_intersect_key($fragment, [$key => true, str_replace('_', '-', $key) => true]),
        )[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    /**
     * Toute la configuration d'une extension dans cet environnement, lue
     * comme {@see self::declaredMapping()} lit une clé : chaque fragment
     * normalisé, puis fusionné dans l'ordre de chargement, sans validation ni
     * défauts (issue #659, la section `security` comparée d'un environnement
     * à l'autre).
     *
     * @return array<array-key, mixed>
     */
    public function declaredConfiguration(string $extension): array
    {
        return $this->mergedFragments($extension, static fn (array $fragment): array => $fragment);
    }

    /**
     * La fusion commune aux deux lectures : `$select` retient, dans chaque
     * fragment, ce qui doit l'être ; un fragment qui n'en garde rien est
     * sauté.
     *
     * @param Closure(array<array-key, mixed>): array<array-key, mixed> $select
     *
     * @return array<array-key, mixed>
     */
    private function mergedFragments(string $extension, Closure $select): array
    {
        $builder = $this->builder();
        $tree = $this->configurationTree($builder, $extension);

        $merged = [];
        foreach ($builder->getExtensionConfig($extension) as $fragment) {
            $declared = $select($fragment);
            if ([] !== $declared) {
                $merged = $tree->merge($merged, $tree->normalize($declared));
            }
        }

        return \is_array($merged) ? $merged : [];
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
            throw new LogicException(\sprintf('L\'extension « %s » n\'expose pas sa configuration.', $extension));
        }

        return $configuration->getConfigTreeBuilder()->buildTree();
    }
}
