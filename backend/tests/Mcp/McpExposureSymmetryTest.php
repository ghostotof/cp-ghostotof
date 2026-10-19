<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\Presentation\Tool\AccessTier;
use App\Mcp\Presentation\Tool\BackedByRoute;
use App\Portfolio\Contribution\Presentation\ApiResource\ContributionResource;
use App\Portfolio\Quality\Presentation\ApiResource\QualityContentResource;
use Mcp\Capability\Registry;
use Mcp\Server\Builder;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Test d'exposition symétrique (ADR 0006 D1, spec 0007 tâche 2) : **le serveur
 * MCP ne sert rien qu'une route du même palier ne sert déjà**, et rien de ce
 * que ces routes servent ne lui échappe sans qu'on l'ait décidé.
 *
 * Construit comme `ApiRouteExposureTest` et `CorpusSourcesTest` : il lit le
 * routeur, l'`AccessMap` et le registre d'outils **réels** du conteneur, pas
 * une déclaration. Deux sens :
 *
 *  1. chaque route des paliers servis sans connexion MCP (anonyme et de base,
 *     ADR 0006 D2 — le palier fixé par `access_control`, comme le fait le
 *     lecteur de payload) a un outil, ou figure dans {@see self::EXCLUDED_ROUTES}
 *     avec sa raison. Toutes les méthodes, pas seulement GET : une écriture
 *     ou un parcours s'y justifie aussi, pour qu'une route qui servirait du
 *     contenu autrement qu'en GET ne passe pas inaperçue ;
 *  2. chaque outil déclare sa route ({@see BackedByRoute}), qui existe avec ce
 *     palier exactement, ou figure dans la liste fermée
 *     {@see self::TOOLS_WITHOUT_ROUTE}.
 *
 * Ajouter un contexte de contenu fait donc échouer la suite tant qu'on n'a pas
 * choisi de l'exposer ou de l'exclure ; retirer une route fait échouer l'outil
 * orphelin. Les deux tests « fictifs » prouvent que l'oubli échoue bien.
 */
final class McpExposureSymmetryTest extends KernelTestCase
{
    /**
     * Routes des paliers anonyme et de base qui n'ont pas d'outil, chacune
     * justifiée : ni contenu, ni lecture.
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array EXCLUDED_ROUTES = [
        // --- Écritures et parcours : aucun contenu servi.
        '/api/contact' => 'Écriture (POST) : le formulaire de contact. get_contact_info donne l\'adresse de sa page publique, l\'outil n\'écrit rien (lecture seule, D1).',
        '/api/account/password-setup' => 'Parcours d\'activation d\'un compte (POST, jeton reçu par e-mail) : aucun contenu.',
        '/api/account/password-setup/validate' => 'Parcours d\'activation (POST) : ne répond que par un statut.',
        '/api/account/base-access' => 'Parcours : émet le jeton du palier de base. Le serveur MCP sert ce palier sans jeton (D2).',
        '/api/login_check' => 'Parcours d\'authentification (POST) : aucun contenu.',
        '/api/logout' => 'Parcours : expire les cookies (POST), aucun contenu.',
        '/api/mcp' => 'Le serveur MCP lui-même.',

        // --- Infrastructure API Platform : aucune donnée métier.
        '/api/docs.{_format}' => 'Schéma OpenAPI, désactivé en préprod et en prod (when@prod).',
        '/api/errors/{status}.{_format}' => 'Ressource d\'erreur interne d\'API Platform.',
        '/api/validation_errors/{id}' => 'Ressource d\'erreur de validation interne d\'API Platform.',
        '/api/.well-known/genid/{id}' => 'Identifiants anonymes générés par API Platform.',
        '/api/.well-known/genid/{id}.{_format}' => 'Idem, variante formatée.',
    ];

    /**
     * Outils sans route adossée : la liste est **fermée** (ADR 0006 D1). Y
     * ajouter un outil demande d'amender l'ADR d'abord.
     *
     * @var array<string, string> nom de l'outil => raison
     */
    private const array TOOLS_WITHOUT_ROUTE = [
        'get_contact_info' => 'Adresse de la page publique du formulaire de contact (palier anonyme) : la route /api/contact est une écriture.',
        'get_nominative_corpus' => 'Corpus nominatif (palier de confiance, connexion MCP) : ses sources sont celles que CorpusSourcesTest fixe.',
    ];

    /** Rôle exigé par `access_control` → palier ; aucun rôle : anonyme. */
    private const array TIER_OF_ROLE = [
        'PUBLIC_ACCESS' => AccessTier::ANONYMOUS,
        'ROLE_USER' => AccessTier::BASE,
        'ROLE_TRUSTED' => AccessTier::TRUSTED,
    ];

    /** Paliers servis sans connexion MCP (ADR 0006 D2). */
    private const array TIERS_WITHOUT_CONNECTION = [AccessTier::ANONYMOUS, AccessTier::BASE];

    public function testEveryRouteOfTheTiersServedWithoutConnectionHasAToolOrAJustifiedExclusion(): void
    {
        self::assertSame([], $this->uncoveredRoutes($this->routes(), $this->tools()));
    }

    public function testEveryToolDeclaresAnExistingRouteOfItsOwnTierOrIsAListedException(): void
    {
        self::assertSame([], $this->toolsWithoutAMatchingRoute($this->routes(), $this->tools()));
    }

    /**
     * Le garde-fou du garde-fou : une route de contenu ajoutée sans outil ni
     * exclusion fait échouer le sens 1.
     */
    public function testAFictiveContentRouteWithoutToolNorExclusionIsCaught(): void
    {
        $routes = $this->routes();
        $routes->add('_api_/fictive/{locale}_get_collection', new Route('/api/fictive/{locale}', ['_api_resource_class' => 'App\Fictive\FictiveResource'], methods: ['GET']));

        self::assertSame(['GET /api/fictive/{locale}'], $this->uncoveredRoutes($routes, $this->tools()));
    }

    /**
     * Un outil qui déclare une ressource sans route, et un outil sans
     * déclaration hors de la liste fermée, font échouer le sens 2.
     */
    public function testAFictiveToolWithoutRouteIsCaught(): void
    {
        $tools = $this->tools();
        $tools['list_fictive'] = new BackedByRoute('App\Fictive\FictiveResource', AccessTier::ANONYMOUS);
        $tools['get_undeclared'] = null;

        self::assertSame(['get_undeclared', 'list_fictive'], $this->toolsWithoutAMatchingRoute($this->routes(), $tools));
    }

    /**
     * Le palier d'un outil est celui de sa route, tel qu'`access_control` le
     * fixe : une route anonyme déclarée de base ou de confiance échoue.
     */
    public function testAToolCannotDeclareAnotherTierThanItsRoutes(): void
    {
        $tools = $this->tools();
        $tools['list_contributions'] = new BackedByRoute(ContributionResource::class, AccessTier::BASE);
        $tools['get_quality'] = new BackedByRoute(QualityContentResource::class, AccessTier::TRUSTED);

        self::assertSame(['get_quality', 'list_contributions'], $this->toolsWithoutAMatchingRoute($this->routes(), $tools));
    }

    /**
     * Le cas que la spec nomme : un outil adossé à une route du palier de
     * base ne peut pas se déclarer anonyme.
     */
    public function testAToolBackedByABaseTierRouteCannotBeDeclaredAnonymous(): void
    {
        $tools = $this->tools();
        $baseTierTool = array_find_key($tools, static fn (?BackedByRoute $route): bool => AccessTier::BASE === $route?->tier);
        self::assertIsString($baseTierTool, 'Aucun outil du palier de base : le cas n\'est plus vérifié.');
        $declared = $tools[$baseTierTool];
        self::assertNotNull($declared);

        $tools[$baseTierTool] = new BackedByRoute($declared->resourceClass, AccessTier::ANONYMOUS);

        self::assertSame([$baseTierTool], $this->toolsWithoutAMatchingRoute($this->routes(), $tools));
    }

    public function testTheExclusionListHasNoStaleEntry(): void
    {
        $paths = array_map(static fn (Route $route): string => $route->getPath(), $this->routes()->all());

        foreach (array_keys(self::EXCLUDED_ROUTES) as $path) {
            self::assertContains($path, $paths, \sprintf('EXCLUDED_ROUTES déclare « %s », qui n\'existe plus au routeur.', $path));
        }
    }

    public function testEveryToolWithoutRouteIsRegisteredAndDeclaresNone(): void
    {
        $tools = $this->tools();

        foreach (array_keys(self::TOOLS_WITHOUT_ROUTE) as $name) {
            self::assertArrayHasKey($name, $tools, \sprintf('TOOLS_WITHOUT_ROUTE nomme « %s », qui n\'est plus un outil.', $name));
            self::assertNull($tools[$name], \sprintf('« %s » déclare une route : il n\'a rien à faire dans la liste des outils sans route.', $name));
        }
    }

    /**
     * Un outil lit par le lecteur de payload, jamais par un repository ni une
     * classe de backoffice (spec 0007) : ni ses dépendances, ni la ressource
     * qu'il déclare.
     */
    public function testNoToolDependsOnARepositoryOrABackofficeClass(): void
    {
        foreach ($this->toolClasses() as $name => $class) {
            foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $dependency = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
                self::assertStringNotContainsString('Repository', $dependency, $name);
                self::assertStringNotContainsString('Backoffice', $dependency, $name);
            }
        }

        foreach ($this->tools() as $name => $route) {
            self::assertStringNotContainsString('Backoffice', $route?->resourceClass ?? '', $name);
        }
    }

    /**
     * Sens 1. Les routes des paliers servis sans connexion qui n'ont ni outil
     * (par la ressource API Platform qu'elles servent) ni exclusion.
     *
     * @param array<string, ?BackedByRoute> $tools
     *
     * @return list<string> « MÉTHODES chemin » de chaque route non couverte
     */
    private function uncoveredRoutes(RouteCollection $routes, array $tools): array
    {
        $exposedResources = array_map(static fn (BackedByRoute $route): string => $route->resourceClass, array_filter($tools));

        $uncovered = [];
        foreach ($routes as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/') || !\in_array($this->tierOf($path), self::TIERS_WITHOUT_CONNECTION, true)) {
                continue;
            }

            if (\array_key_exists($path, self::EXCLUDED_ROUTES) || \in_array($route->getDefault('_api_resource_class'), $exposedResources, true)) {
                continue;
            }

            $uncovered[] = \sprintf('%s %s', implode('|', $route->getMethods()) ?: 'ANY', $path);
        }
        sort($uncovered);

        return $uncovered;
    }

    /**
     * Sens 2. Les outils qui ne sont ni dans la liste fermée, ni adossés à
     * une route GET existante de leur ressource dont le palier est exactement
     * celui qu'ils déclarent.
     *
     * @param array<string, ?BackedByRoute> $tools
     *
     * @return list<string> noms des outils fautifs
     */
    private function toolsWithoutAMatchingRoute(RouteCollection $routes, array $tools): array
    {
        $faulty = [];
        foreach ($tools as $name => $declared) {
            if (null === $declared) {
                if (!\array_key_exists($name, self::TOOLS_WITHOUT_ROUTE)) {
                    $faulty[] = $name;
                }

                continue;
            }

            if (!$this->hasGetRouteOfTier($routes, $declared)) {
                $faulty[] = $name;
            }
        }
        sort($faulty);

        return $faulty;
    }

    private function hasGetRouteOfTier(RouteCollection $routes, BackedByRoute $declared): bool
    {
        foreach ($routes as $route) {
            if ($declared->resourceClass === $route->getDefault('_api_resource_class')
                && \in_array(Request::METHOD_GET, $route->getMethods(), true)
                && $declared->tier === $this->tierOf($route->getPath())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le palier d'un chemin, tel que l'`AccessMap` du firewall le fixe — la
     * même lecture que le lecteur de payload. `null` : au-delà du palier de
     * confiance (le backoffice).
     */
    private function tierOf(string $path): ?AccessTier
    {
        $accessMap = self::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);

        [$roles] = $accessMap->getPatterns(Request::create($path));
        $roles ??= [];

        if ([] === $roles) {
            return AccessTier::ANONYMOUS;
        }

        return 1 === \count($roles) ? (self::TIER_OF_ROLE[$roles[0]] ?? null) : null;
    }

    private function routes(): RouteCollection
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        // Une copie : les tests « fictifs » y ajoutent une route.
        return clone $router->getRouteCollection();
    }

    /**
     * Les outils du registre réel, avec la route que chacun déclare.
     *
     * @return array<string, ?BackedByRoute>
     */
    private function tools(): array
    {
        $tools = [];
        foreach ($this->toolClasses() as $name => $class) {
            $attribute = new ReflectionClass($class)->getAttributes(BackedByRoute::class)[0] ?? null;
            $tools[$name] = $attribute?->newInstance();
        }

        return $tools;
    }

    /**
     * Nom → classe de chaque outil du serveur `portfolio`, lus dans son
     * registre une fois le serveur construit (le registre se remplit à la
     * construction, comme à la première requête).
     *
     * @return array<string, class-string>
     */
    private function toolClasses(): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $builder = $container->get('mcp.server.portfolio.builder');
        self::assertInstanceOf(Builder::class, $builder);
        $builder->build();
        $registry = $container->get('mcp.server.portfolio.registry');
        self::assertInstanceOf(Registry::class, $registry);

        $classes = [];
        foreach (array_keys(iterator_to_array($registry->getTools())) as $name) {
            $handler = $registry->getTool($name)->handler;
            self::assertIsArray($handler, \sprintf('L\'outil « %s » n\'est pas une méthode de classe.', $name));
            self::assertIsString($handler[0]);
            self::assertTrue(class_exists($handler[0]));
            $classes[$name] = $handler[0];
        }
        self::assertNotEmpty($classes, 'Aucun outil au registre : le test ne vérifie plus rien.');

        return $classes;
    }
}
