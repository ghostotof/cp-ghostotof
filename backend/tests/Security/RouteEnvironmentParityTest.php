<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * ApiRouteExposureTest, RouterScopeTest et ItemRouteRequirementTest parcourent
 * le routeur de l'environnement de **test** (issue #659, point I3 de l'audit
 * de #641). Une route déclarée pour `prod` seulement — préproduction et
 * production tournent en APP_ENV=prod — serait servie sans qu'aucun d'eux l'ait
 * vue ; une route propre à `test` les rendrait verts sur un routeur que la
 * production n'a pas.
 *
 * Chaque environnement est donc démarré (EnvironmentConfigKernel::routes()) et
 * sa collection comparée à celle de `test`, route par route et dans l'ordre —
 * le routeur retient la première qui correspond. Le tri par environnement est
 * celui du noyau lui-même : config/routes/<env>/, `when@<env>` en YAML comme en
 * PHP, `#[Route(env: …)]`, rien n'est relu à part. Une route propre à un
 * environnement doit figurer dans PROPER_TO_ENVIRONMENT, justifiée.
 *
 * Pas de garde sur les valeurs paramétrées, à la différence de
 * SecurityConfigEnvironmentParityTest : le routeur résout un `%paramètre%` au
 * chargement, la comparaison porte donc sur la valeur de chaque environnement,
 * et il refuse `%env()%` dans une route.
 */
final class RouteEnvironmentParityTest extends TestCase
{
    /**
     * Environnement => nom de route => pourquoi elle n'existe que là.
     *
     * @var array<string, array<string, string>>
     */
    private const array PROPER_TO_ENVIRONMENT = [
        'dev' => [
            '_preview_error' => 'Prévisualisation des pages d\'erreur de FrameworkBundle, `/_error/{code}.{_format}` (config/routes/framework.yaml, when@dev) : hors `/api` et de tout firewall, elle ne sert que les pages d\'erreur de Symfony. `dev` ne tourne que sur un poste : les images de préproduction et de production fixent APP_ENV=prod (docker/php/Dockerfile).',
        ],
    ];

    /**
     * Ce qui est rapporté quand les routes communes se suivent dans un autre
     * ordre.
     */
    private const string ORDER = '(ordre des routes)';

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
            'Route propre à un environnement : les gardes de l\'exposition, qui lisent le routeur de test, ne la voient pas. La rendre commune, ou la justifier dans PROPER_TO_ENVIRONMENT.',
        );
    }

    /**
     * Une justification qui ne sert plus se retire : sinon elle couvrirait
     * demain une route du même nom que personne n'a justifiée.
     */
    public function testEveryJustifiedRouteIsStillProperToItsEnvironment(): void
    {
        self::assertSame(
            [],
            array_values(array_diff($this->justified(), $this->differences())),
            'Route justifiée dans PROPER_TO_ENVIRONMENT mais identique à celle de test : retirer l\'entrée.',
        );
    }

    /**
     * La comparaison elle-même, sur deux collections construites ici : chaque
     * partie d'une route qui décide de ce qui est servi, une route en plus,
     * une en moins.
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

        $other->get('path')?->setPath('/elsewhere');
        $other->get('host')?->setHost('admin.example.test');
        $other->get('schemes')?->setSchemes(['http']);
        $other->get('methods')?->setMethods(['POST']);
        $other->get('requirements')?->setRequirement('id', '\d+');
        $other->get('defaults')?->setDefault('_controller', 'App\Elsewhere');
        $other->get('options')?->setOption('utf8', true);
        $other->get('condition')?->setCondition('request.isSecure()');

        self::assertSame(
            ['added', 'condition', 'defaults', 'gone', 'host', 'methods', 'options', 'path', 'requirements', 'schemes'],
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

        self::assertSame([self::ORDER], $this->differingRoutes($reference, $other));
    }

    /**
     * @return list<string> « env ≠ test : route » de PROPER_TO_ENVIRONMENT
     */
    private function justified(): array
    {
        $justified = [];
        foreach (self::PROPER_TO_ENVIRONMENT as $environment => $routes) {
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
        foreach (array_diff(EnvironmentConfigKernel::allowedEnvironments(), ['test']) as $environment) {
            foreach ($this->differingRoutes($reference, $this->routes($environment)) as $route) {
                $found[] = \sprintf('%s ≠ test : %s', $environment, $route);
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string> les routes absentes d'un côté ou différentes, triées, plus ORDER si les routes communes se suivent autrement
     */
    private function differingRoutes(RouteCollection $reference, RouteCollection $other): array
    {
        $referenceRoutes = $reference->all();
        $otherRoutes = $other->all();

        $found = [];
        foreach (array_unique([...array_keys($referenceRoutes), ...array_keys($otherRoutes)]) as $name) {
            $referenceRoute = $referenceRoutes[$name] ?? null;
            $otherRoute = $otherRoutes[$name] ?? null;
            if (null === $referenceRoute || null === $otherRoute || $this->signature($referenceRoute) !== $this->signature($otherRoute)) {
                $found[] = $name;
            }
        }
        sort($found);

        $common = array_keys(array_intersect_key($referenceRoutes, $otherRoutes));
        if (array_keys(array_intersect_key($otherRoutes, $referenceRoutes)) !== $common) {
            $found[] = self::ORDER;
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
        return self::$routes[$environment] ??= new EnvironmentConfigKernel($environment)->routes();
    }
}
