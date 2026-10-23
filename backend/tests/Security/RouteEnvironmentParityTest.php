<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Security\Fixtures\RouteAttributes\EnvironmentRoutedController;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\EnvironmentConfigKernel;
use App\Tests\Support\EnvironmentRoutesKernel;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Alias;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * ApiRouteExposureTest, RouterScopeTest et ItemRouteRequirementTest parcourent
 * le routeur de l'environnement de **test** (issue #659, point I3 de l'audit
 * de #641). Une route déclarée pour `prod` seulement — préproduction et
 * production tournent en APP_ENV=prod — serait servie sans qu'aucun d'eux l'ait
 * vue ; une route propre à `test` les rendrait verts sur un routeur que la
 * production n'a pas.
 *
 * Chaque environnement est donc démarré (EnvironmentRoutesKernel::routes()) et
 * sa collection comparée à celle de `test`, route par route et dans l'ordre —
 * le routeur retient la première qui correspond —, alias compris. Le tri par
 * environnement est celui du noyau lui-même : config/routes/<env>/,
 * `when@<env>` en YAML comme en PHP, `#[Route(env: …)]`, rien n'est relu à
 * part. Une route propre à un environnement doit figurer dans
 * ENVIRONMENT_SPECIFIC, justifiée et son chemin figé.
 *
 * Les formes elles-mêmes sont refusées aussi, à la lettre : un répertoire
 * config/routes/<env>/, un bloc `when@<env>` ou un `->env()` dans un fichier
 * de routes (sauf ENVIRONMENT_BLOCKS), un `#[Route(env: …)]` dans src/. Une
 * telle forme qui redéclarerait des routes identiques serait sans effet sur
 * la comparaison, mais elle invite la suivante à diverger.
 *
 * Pas de garde sur les valeurs paramétrées, à la différence de
 * SecurityConfigEnvironmentParityTest : le routeur résout un `%paramètre%` au
 * chargement, la comparaison porte donc sur la valeur de chaque environnement,
 * et il refuse `%env()%` dans une route. Chaque environnement est lu hors
 * débogage (voir EnvironmentRoutesKernel).
 */
final class RouteEnvironmentParityTest extends TestCase
{
    /**
     * « fichier de routes : when@<env> » => pourquoi le bloc est là, le
     * fichier relatif à config/.
     */
    private const array ENVIRONMENT_BLOCKS = [
        'routes/framework.yaml : when@dev' => 'Importe `_preview_error`, justifiée dans ENVIRONMENT_SPECIFIC.',
    ];

    private const string ROUTES_FIXTURE_CONFIG = __DIR__.'/Fixtures/RoutesProject/config';

    private const string ROUTE_ATTRIBUTES_FIXTURE = __DIR__.'/Fixtures/RouteAttributes';

    /**
     * Environnement => nom de route => son chemin, figé, et pourquoi elle
     * n'existe que là. Le chemin figé empêche la justification de couvrir une
     * route redéfinie ailleurs, sous `/api` par exemple.
     *
     * @var array<string, array<string, array{path: string, reason: string}>>
     */
    private const array ENVIRONMENT_SPECIFIC = [
        'dev' => [
            '_preview_error' => [
                'path' => '/_error/{code}.{_format}',
                'reason' => 'Prévisualisation des pages d\'erreur de FrameworkBundle (config/routes/framework.yaml, when@dev) : hors `/api` et de tout firewall, elle ne sert que les pages d\'erreur de Symfony. `dev` ne tourne que sur un poste : les images de préproduction et de production fixent APP_ENV=prod (docker/php/Dockerfile).',
            ],
        ],
    ];

    /**
     * Le marqueur rapporté, parmi les noms de routes, quand les routes
     * communes se suivent dans un autre ordre.
     */
    private const string ROUTE_ORDER_DIFFERS = '(ordre des routes)';

    /**
     * Une collection par environnement, chargée une fois : chaque chargement
     * compile un conteneur.
     *
     * @var array<string, RouteCollection>
     */
    private static array $routes = [];

    public function testEveryEnvironmentServesTheSameRoutesAsTest(): void
    {
        self::assertSame(
            [],
            array_values(array_diff($this->differences(), $this->justified())),
            'Route propre à un environnement : les gardes de l\'exposition, qui lisent le routeur de test, ne la voient pas. La rendre commune, ou la justifier dans ENVIRONMENT_SPECIFIC.',
        );
    }

    /**
     * Une justification qui ne sert plus se retire : sinon elle couvrirait
     * demain une route du même nom que personne n'a justifiée.
     */
    public function testEveryJustifiedRouteIsStillSpecificToItsEnvironment(): void
    {
        self::assertSame(
            [],
            array_values(array_diff($this->justified(), $this->differences())),
            'Route justifiée dans ENVIRONMENT_SPECIFIC mais identique à celle de test : retirer l\'entrée.',
        );
    }

    public function testEveryJustifiedRouteKeepsItsPath(): void
    {
        foreach (self::ENVIRONMENT_SPECIFIC as $environment => $routes) {
            foreach ($routes as $name => $justification) {
                self::assertSame(
                    $justification['path'],
                    $this->routes($environment)->get($name)?->getPath(),
                    \sprintf('%s a changé de chemin en %s : revoir sa justification avant de mettre à jour ENVIRONMENT_SPECIFIC.', $name, $environment),
                );
            }
        }
    }

    /**
     * La comparaison elle-même, sur deux collections construites ici : chaque
     * partie d'une route qui décide de ce qui est servi, une route en plus,
     * une en moins, un alias déplacé, un alias en plus.
     */
    public function testTheComparisonSeesEveryPartOfARoute(): void
    {
        $reference = new RouteCollection();
        $other = new RouteCollection();
        foreach (['same', 'path', 'host', 'schemes', 'methods', 'requirements', 'defaults', 'options', 'condition'] as $name) {
            $reference->add($name, new Route('/'.$name));
            $other->add($name, new Route('/'.$name));
        }
        $reference->add('gone', new Route('/gone'));
        $other->add('added', new Route('/added'));
        $reference->addAlias('same_alias', 'same');
        $other->addAlias('same_alias', 'same');
        $reference->addAlias('moved_alias', 'same');
        $other->addAlias('moved_alias', 'path');
        $other->addAlias('extra_alias', 'same');

        $other->get('path')?->setPath('/elsewhere');
        $other->get('host')?->setHost('admin.example.test');
        $other->get('schemes')?->setSchemes(['http']);
        $other->get('methods')?->setMethods(['POST']);
        $other->get('requirements')?->setRequirement('id', '\d+');
        $other->get('defaults')?->setDefault('_controller', 'App\Elsewhere');
        $other->get('options')?->setOption('utf8', true);
        $other->get('condition')?->setCondition('request.isSecure()');

        self::assertSame(
            ['added', 'alias extra_alias', 'alias moved_alias', 'condition', 'defaults', 'gone', 'host', 'methods', 'options', 'path', 'requirements', 'schemes'],
            $this->differingRoutes($reference, $other),
        );
    }

    /**
     * Mêmes routes, autre ordre : une autre route gagne là où deux se
     * recouvrent.
     */
    public function testTheComparisonSeesTheOrderOfTheRoutes(): void
    {
        $reference = new RouteCollection();
        $reference->add('item', new Route('/things/{id}'));
        $reference->add('special', new Route('/things/special'));
        $other = new RouteCollection();
        $other->add('special', new Route('/things/special'));
        $other->add('item', new Route('/things/{id}'));

        self::assertSame([self::ROUTE_ORDER_DIFFERS], $this->differingRoutes($reference, $other));
    }

    public function testNoRouteDirectoryIsSpecificToAnEnvironment(): void
    {
        self::assertSame([], $this->environmentDirectories(RepositoryFiles::backend('config')), 'Répertoire de routes propre à un environnement : déclarer ses routes pour tous.');
    }

    public function testNoRouteFileDeclaresAnUnjustifiedEnvironmentBlock(): void
    {
        self::assertSame(
            [],
            array_values(array_diff($this->environmentBlocks(RepositoryFiles::backend('config')), array_keys(self::ENVIRONMENT_BLOCKS))),
            'Bloc d\'environnement dans un fichier de routes : déclarer ses routes pour tous, ou le justifier dans ENVIRONMENT_BLOCKS.',
        );
    }

    public function testNoRouteAttributeIsRestrictedToEnvironments(): void
    {
        self::assertSame([], $this->environmentRouteAttributes(RepositoryFiles::backend('src')), '#[Route(env: …)] dans src/ : déclarer la route pour tous les environnements.');
    }

    public function testEveryJustifiedBlockStillExists(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::ENVIRONMENT_BLOCKS), $this->environmentBlocks(RepositoryFiles::backend('config')))),
            'Bloc justifié dans ENVIRONMENT_BLOCKS mais absent : retirer l\'entrée.',
        );
    }

    /**
     * Chaque forme littérale, sur des fixtures : un répertoire, un bloc YAML
     * dans le point d'entrée et dans un fichier importé, un bloc PHP au format
     * tableau, un `->env()` au format closure, un attribut sur la classe et
     * sur une méthode ; un fichier et une méthode communs ne ressortent pas.
     */
    public function testEveryLiteralFormIsFound(): void
    {
        self::assertSame(['routes/prod/'], $this->environmentDirectories(self::ROUTES_FIXTURE_CONFIG));
        self::assertSame([
            'routes.yaml : when@test',
            'routes/array_form.php : when@dev',
            'routes/blocks.yaml : when@prod',
            'routes/closure_form.php : ->env()',
        ], $this->environmentBlocks(self::ROUTES_FIXTURE_CONFIG));
        self::assertSame([
            EnvironmentRoutedController::class,
            EnvironmentRoutedController::class.'::devOnly',
        ], $this->environmentRouteAttributes(self::ROUTE_ATTRIBUTES_FIXTURE));
    }

    /**
     * @return list<string> « routes/<env>/ » pour chaque environnement admis qui a son répertoire
     */
    private function environmentDirectories(string $configDirectory): array
    {
        return array_values(array_map(
            static fn (string $environment): string => 'routes/'.$environment.'/',
            array_filter(EnvironmentConfigKernel::allowedEnvironments(), static fn (string $environment): bool => is_dir($configDirectory.'/routes/'.$environment)),
        ));
    }

    /**
     * Les fichiers que le noyau charge comme routes — routes.{yaml,php} et
     * routes/*.{yaml,php} — et, dans chacun, les blocs `when@<env>` (clés YAML
     * de premier niveau, chaînes PHP) et les `->env()` d'une closure PHP.
     *
     * @return list<string> « fichier : when@<env> » ou « fichier : ->env() », triées
     */
    private function environmentBlocks(string $configDirectory): array
    {
        $files = array_values(array_filter(['routes.yaml', 'routes.php'], static fn (string $file): bool => is_file($configDirectory.'/'.$file)));
        $paths = glob($configDirectory.'/routes/*');
        foreach (\is_array($paths) ? $paths : [] as $path) {
            if (is_file($path)) {
                $files[] = 'routes/'.basename($path);
            }
        }

        $found = [];
        foreach ($files as $file) {
            $content = RepositoryFiles::read($configDirectory.'/'.$file);
            if (str_ends_with($file, '.yaml')) {
                $parsed = Yaml::parse($content);
                foreach (\is_array($parsed) ? array_keys($parsed) : [] as $key) {
                    if (str_starts_with((string) $key, 'when@')) {
                        $found[] = \sprintf('%s : %s', $file, $key);
                    }
                }
            } elseif (str_ends_with($file, '.php')) {
                preg_match_all('/when@\w+/', $content, $blocks);
                foreach (array_unique($blocks[0]) as $block) {
                    $found[] = \sprintf('%s : %s', $file, $block);
                }
                if (str_contains($content, '->env()')) {
                    $found[] = \sprintf('%s : ->env()', $file);
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Les classes et méthodes du répertoire qui portent un `#[Route]` restreint
     * à des environnements.
     *
     * @return list<string> « Classe » ou « Classe::méthode », triées
     */
    private function environmentRouteAttributes(string $directory): array
    {
        $found = [];
        foreach (DeclaredClasses::classes($directory) as $class) {
            $reflection = new ReflectionClass($class);
            foreach ([$reflection, ...$reflection->getMethods()] as $target) {
                foreach ($target->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    if ([] !== $attribute->newInstance()->envs) {
                        $found[] = $target instanceof ReflectionMethod ? $class.'::'.$target->getName() : $class;
                    }
                }
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * @return list<string> « env ≠ test : route » d'ENVIRONMENT_SPECIFIC
     */
    private function justified(): array
    {
        $justified = [];
        foreach (self::ENVIRONMENT_SPECIFIC as $environment => $routes) {
            foreach (array_keys($routes) as $name) {
                $justified[] = \sprintf('%s ≠ test : %s', $environment, $name);
            }
        }

        return $justified;
    }

    /**
     * @return list<string> « env ≠ test : route », triées
     */
    private function differences(): array
    {
        $found = [];
        $reference = $this->routes('test');
        foreach (EnvironmentConfigKernel::comparedEnvironments() as $environment) {
            foreach ($this->differingRoutes($reference, $this->routes($environment)) as $route) {
                $found[] = \sprintf('%s ≠ test : %s', $environment, $route);
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string> les routes et les alias (« alias nom ») absents d'un côté ou différents, triés, plus ROUTE_ORDER_DIFFERS si les routes communes se suivent autrement
     */
    private function differingRoutes(RouteCollection $reference, RouteCollection $other): array
    {
        $found = [
            ...$this->differingEntries($reference->all(), $other->all(), $this->signature(...), ''),
            ...$this->differingEntries($reference->getAliases(), $other->getAliases(), serialize(...), 'alias '),
        ];
        sort($found);

        $referenceRoutes = $reference->all();
        $otherRoutes = $other->all();
        if (array_keys(array_intersect_key($otherRoutes, $referenceRoutes)) !== array_keys(array_intersect_key($referenceRoutes, $otherRoutes))) {
            $found[] = self::ROUTE_ORDER_DIFFERS;
        }

        return $found;
    }

    /**
     * @template T of Route|Alias
     *
     * @param array<string, T>    $reference
     * @param array<string, T>    $other
     * @param callable(T): string $signature
     *
     * @return list<string> les noms, préfixés, absents d'un côté ou dont la signature diffère
     */
    private function differingEntries(array $reference, array $other, callable $signature, string $prefix): array
    {
        $found = [];
        foreach (array_unique([...array_keys($reference), ...array_keys($other)]) as $name) {
            $referenceEntry = $reference[$name] ?? null;
            $otherEntry = $other[$name] ?? null;
            if (null === $referenceEntry || null === $otherEntry || $signature($referenceEntry) !== $signature($otherEntry)) {
                $found[] = $prefix.$name;
            }
        }

        return $found;
    }

    /**
     * Tout ce qui décide si et comment une route est servie. Sérialisé : la
     * comparaison est stricte sur les types, et compare un objet par sa
     * valeur, pas par son identité.
     */
    private function signature(Route $route): string
    {
        return serialize([
            $route->getPath(),
            $route->getHost(),
            $route->getSchemes(),
            $route->getMethods(),
            $route->getRequirements(),
            $route->getDefaults(),
            $route->getOptions(),
            $route->getCondition(),
        ]);
    }

    private function routes(string $environment): RouteCollection
    {
        return self::$routes[$environment] ??= new EnvironmentRoutesKernel($environment)->routes();
    }
}
