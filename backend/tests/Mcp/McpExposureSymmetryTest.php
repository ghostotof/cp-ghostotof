<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use ApiPlatform\Metadata\Exception\OperationNotFoundException;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Mcp\Application\NominativeCorpusReaderInterface;
use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Mcp\Connection\Application\BearerChallenge;
use App\Mcp\Presentation\Tool\AccessTier;
use App\Mcp\Presentation\Tool\BackedByRoute;
use App\Portfolio\Contribution\Presentation\ApiResource\ContributionResource;
use App\Portfolio\Quality\Presentation\ApiResource\QualityContentResource;
use App\Tests\Mcp\Fixtures\CallerReadingProvider;
use App\Tests\Mcp\Infrastructure\ApiPlatform\Fixtures\PrefixedResource;
use App\Tests\Mcp\Presentation\Tool\GetAboutToolTest;
use App\Tests\Mcp\Presentation\Tool\RouteBackedToolsTest;
use App\Tests\Mcp\Support\ReadsPortfolioRegistry;
use App\Tests\Mcp\Support\UnreadableAccessRuleException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Test d'exposition symétrique (ADR 0006 D1, spec 0007 tâche 2) : **le serveur
 * MCP ne sert rien qu'une route du même palier ne sert déjà**, et rien de ce
 * que ces routes servent ne lui échappe sans qu'on l'ait décidé.
 *
 * Construit comme `ApiRouteExposureTest` et `CorpusSourcesTest` : il lit le
 * routeur, l'`AccessMap`, les métadonnées API Platform et le registre d'outils
 * **réels** du conteneur, pas une déclaration. Deux sens :
 *
 *  1. chaque route des paliers servis sans connexion MCP (anonyme et de base,
 *     ADR 0006 D2) est couverte : sa route GET de contenu par l'outil qui la
 *     lit, toute autre route — une autre méthode sur la même ressource
 *     comprise — par une entrée de {@see self::EXCLUDED_ROUTES}, à ses
 *     méthodes exactes, avec sa raison ;
 *  2. chaque outil déclare sa route ({@see BackedByRoute}) : une route GET de
 *     contenu de sa ressource, de ce palier exactement, et ce palier est servi
 *     sans connexion — ou il figure dans la liste fermée
 *     {@see self::TOOLS_WITHOUT_ROUTE}.
 *
 * Le palier d'une route est celui que fixe `access_control` (première règle
 * qui correspond, comme le firewall), relevé au-delà du palier de confiance
 * quand l'opération porte un attribut `security` — le lecteur de payload la
 * refuse alors. Une règle à plusieurs rôles ou à un rôle inconnu fait échouer
 * le test plutôt que de sortir la route du sens 1.
 *
 * S'y ajoutent ce qui rend l'attribut digne de confiance : chaque outil adossé
 * n'injecte que le lecteur de payload (il ne peut lire que par lui), et son
 * égalité avec la route est prouvée par `RouteBackedToolsTest` ou par son
 * propre test. Les cas « fictifs » prouvent que chaque oubli échoue.
 */
final class McpExposureSymmetryTest extends KernelTestCase
{
    use ReadsPortfolioRegistry;

    /**
     * Routes des paliers anonyme et de base sans outil, chacune justifiée : ni
     * contenu, ni lecture. La clé porte les méthodes exactes de la route : une
     * méthode ajoutée change la clé, et demande sa propre justification.
     *
     * @var array<string, string> « MÉTHODES chemin » => raison
     */
    private const array EXCLUDED_ROUTES = [
        // --- Écritures et parcours : aucun contenu servi.
        'POST /api/contact' => 'Écriture : le formulaire de contact. get_contact_info donne l\'adresse de sa page publique ; le serveur n\'écrit rien (lecture seule, D1).',
        'POST /api/account/password-setup' => 'Parcours d\'activation d\'un compte (jeton reçu par e-mail) : aucun contenu.',
        'POST /api/account/password-setup/validate' => 'Parcours d\'activation : ne répond que par un statut.',
        'POST /api/account/base-access' => 'Parcours : émet le jeton du palier de base. Le serveur MCP sert ce palier sans jeton (D2).',
        'POST /api/login_check' => 'Parcours d\'authentification : aucun contenu.',
        'POST /api/logout' => 'Parcours : expire les cookies, aucun contenu.',
        'GET|POST|DELETE|OPTIONS /api/mcp' => 'Le serveur MCP lui-même.',
        'GET /api/oauth/authorize' => 'Parcours OAuth de la connexion MCP (#647) : valide une demande d\'autorisation et redirige, aucun contenu.',
        'POST /api/oauth/token' => 'Parcours OAuth de la connexion MCP (#647) : échange un code ou un rafraîchissement contre des jetons, aucun contenu.',
        'POST /api/oauth/revoke' => 'Parcours OAuth de la connexion MCP (#647) : révoque un jeton (RFC 7009), aucun contenu.',

        // --- Infrastructure API Platform : aucune donnée métier.
        'GET|HEAD /api/docs.{_format}' => 'Schéma OpenAPI, désactivé en préprod et en prod (when@prod).',
        'GET /api/errors/{status}.{_format}' => 'Ressource d\'erreur interne d\'API Platform.',
        'GET /api/validation_errors/{id}' => 'Ressource d\'erreur de validation interne d\'API Platform (une route par format).',
        'GET|HEAD /api/validation_errors/{id}' => 'Idem, la route générique.',
        'GET|HEAD /api/.well-known/genid/{id}' => 'Identifiants anonymes générés par API Platform.',
        'GET /api/.well-known/genid/{id}.{_format}' => 'Idem, variante formatée (opération not_exposed).',
    ];

    /**
     * Outils sans route adossée : la liste est **fermée** (ADR 0006 D1). Y
     * ajouter un outil demande d'amender l'ADR d'abord. Chacun avec les seuls
     * types que son constructeur peut recevoir. Publique : la garde D8
     * (`AuthorNeverNamedTest`) lit la sortie de ces outils-là, que le serveur
     * écrit lui-même au lieu de la tenir d'une route.
     *
     * @var array<string, array{string, list<string>}> nom => [raison, types du constructeur]
     */
    public const array TOOLS_WITHOUT_ROUTE = [
        'get_contact_info' => ['Adresse de la page publique du formulaire de contact (palier anonyme) : la route /api/contact est une écriture.', ['string']],
        'get_nominative_corpus' => [
            'Corpus nominatif (palier de confiance, connexion MCP, #648) : ses sources sont celles que CorpusSourcesTest fixe, rendues par le renderer de l\'assistant de parcours. L\'outil délègue l\'accès, le rendu et l\'audit à NominativeCorpusReaderInterface, qui lit l\'identité par Security seulement (ADR 0006 D4) ; refusé, l\'erreur d\'outil et son défi (BearerChallenge) ; au-delà de sa borne, le refus et un warning aux tailles seules (LoggerInterface).',
            [NominativeCorpusReaderInterface::class, BearerChallenge::class, LoggerInterface::class],
        ],
    ];

    /**
     * Outils adossés dont l'égalité avec la route est prouvée par leur propre
     * test plutôt que par `RouteBackedToolsTest`.
     *
     * @var array<string, class-string> nom => test
     */
    private const array EQUALITY_TESTED_APART = [
        'get_about' => GetAboutToolTest::class,
    ];

    /** Rôle exigé par `access_control` → palier ; aucune règle : anonyme. */
    private const array TIER_OF_ROLE = [
        'PUBLIC_ACCESS' => AccessTier::ANONYMOUS,
        'ROLE_USER' => AccessTier::BASE,
        'ROLE_TRUSTED' => AccessTier::TRUSTED,
    ];

    /** Rôles au-delà de tout palier : le backoffice. */
    private const array ROLES_ABOVE_TIERS = ['ROLE_SUPER'];

    /** Paliers servis sans connexion MCP (ADR 0006 D2). */
    private const array TIERS_WITHOUT_CONNECTION = [AccessTier::ANONYMOUS, AccessTier::BASE];

    /** Ce qui donne à un provider la requête ou l'appelant, que le lecteur ne lui fournit pas. */
    private const array CALLER_DEPENDENCIES = [
        RequestStack::class,
        Security::class,
        TokenStorageInterface::class,
        AuthorizationCheckerInterface::class,
    ];

    /** Contrôleur des opérations qu'API Platform synthétise pour ses IRI : pas des routes de contenu. */
    private const string NOT_EXPOSED_CONTROLLER = 'api_platform.action.not_exposed';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testEveryRouteOfTheTiersServedWithoutConnectionIsCoveredByAToolOrAJustifiedExclusion(): void
    {
        self::assertSame([], $this->uncoveredRoutes($this->routes(), $this->tools()));
    }

    public function testEveryToolDeclaresAContentRouteOfItsOwnTierServedWithoutConnectionOrIsAListedException(): void
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
        $routes->add('_api_/fictive/{locale}_get_collection', $this->contentRoute('/api/fictive/{locale}', 'App\Fictive\FictiveResource', ['GET']));

        self::assertSame(['GET /api/fictive/{locale}'], $this->uncoveredRoutes($routes, $this->tools()));
    }

    /**
     * Une autre méthode sur une ressource exposée n'est pas couverte par
     * l'outil, qui ne lit que le GET : elle demande sa propre exclusion.
     */
    public function testAnotherMethodOnAnExposedResourceIsNotCoveredByItsTool(): void
    {
        $routes = $this->routes();
        $routes->add('_api_/contributions/{locale}_post', $this->contentRoute('/api/contributions/{locale}', ContributionResource::class, ['POST']));

        self::assertSame(['POST /api/contributions/{locale}'], $this->uncoveredRoutes($routes, $this->tools()));
    }

    /**
     * Une exclusion vaut pour les méthodes exactes de sa clé : un GET ajouté
     * sur un chemin exclu pour son POST n'est pas couvert.
     */
    public function testAnExclusionCoversItsExactMethodsOnly(): void
    {
        $routes = $this->routes();
        $routes->add('fictive_contact_get', new Route('/api/contact', methods: ['GET']));

        self::assertSame(['GET /api/contact'], $this->uncoveredRoutes($routes, $this->tools()));
    }

    /**
     * Une règle `access_control` à plusieurs rôles ne sort pas la route du
     * sens 1 en silence : le test échoue, faute de savoir lire son palier.
     */
    public function testARuleWithSeveralRolesFailsTheTestInsteadOfHidingTheRoute(): void
    {
        $this->expectException(UnreadableAccessRuleException::class);
        $this->expectExceptionMessageMatches('/plusieurs rôles/');

        $this->tierOfRoles(['ROLE_USER', 'ROLE_TRUSTED'], '/api/fictive');
    }

    public function testAnUnknownRoleFailsTheTestInsteadOfHidingTheRoute(): void
    {
        $this->expectException(UnreadableAccessRuleException::class);
        $this->expectExceptionMessageMatches('/rôle inconnu/');

        $this->tierOfRoles(['ROLE_FICTIVE'], '/api/fictive');
    }

    /**
     * Un outil qui déclare une ressource sans route, et un outil sans
     * déclaration hors de la liste fermée, font échouer le sens 2.
     */
    public function testAFictiveToolWithoutRouteIsCaught(): void
    {
        $tools = $this->tools();
        $tools['list_fictive'] = new BackedByRoute(PrefixedResource::class, AccessTier::ANONYMOUS);
        $tools['get_undeclared'] = null;

        self::assertSame(['get_undeclared', 'list_fictive'], $this->toolsWithoutAMatchingRoute($this->routes(), $tools));
    }

    /**
     * Le palier d'un outil est celui de sa route : une route anonyme déclarée
     * de base, ou de confiance, échoue.
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

    /**
     * En phase 1, aucun outil adossé n'est du palier de confiance : rien ne
     * vérifie encore la connexion MCP. Une route de ce palier déclarée comme
     * telle échoue quand même.
     */
    public function testAToolOfTheTrustedTierIsRefusedInPhaseOne(): void
    {
        $routes = $this->routes();
        $routes->add('_api_/fictive-cv_get', $this->contentRoute('/api/cv/fictive', PrefixedResource::class, ['GET']));
        $tools = $this->tools();
        $tools['get_fictive_cv'] = new BackedByRoute(PrefixedResource::class, AccessTier::TRUSTED);

        self::assertSame(['get_fictive_cv'], $this->toolsWithoutAMatchingRoute($routes, $tools));
    }

    /**
     * Les routes qu'API Platform synthétise pour ses IRI (`not_exposed`) ne
     * sont pas du contenu : elles ne satisfont pas une déclaration. Le
     * routeur réel en porte une, anonyme, au nom d'une ressource du
     * backoffice.
     */
    public function testANotExposedRouteDoesNotSatisfyADeclaration(): void
    {
        $notExposed = array_find($this->routes()->all(), static fn (Route $route): bool => self::NOT_EXPOSED_CONTROLLER === $route->getDefault('_controller') && null !== $route->getDefault('_api_resource_class'));
        self::assertInstanceOf(Route::class, $notExposed, 'Plus de route not_exposed au routeur : le cas n\'est plus vérifié.');
        $resource = $notExposed->getDefault('_api_resource_class');
        self::assertIsString($resource);
        self::assertTrue(class_exists($resource));

        $tools = $this->tools();
        $tools['get_ghost'] = new BackedByRoute($resource, AccessTier::ANONYMOUS);

        self::assertSame(['get_ghost'], $this->toolsWithoutAMatchingRoute($this->routes(), $tools));
    }

    public function testTheExclusionListHasNoStaleEntry(): void
    {
        $signatures = array_map($this->signatureOf(...), array_values($this->routes()->all()));

        foreach (array_keys(self::EXCLUDED_ROUTES) as $signature) {
            self::assertContains($signature, $signatures, \sprintf('EXCLUDED_ROUTES déclare « %s », que le routeur ne porte plus.', $signature));
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
     * Un outil adossé ne reçoit que le lecteur de payload : il ne peut lire
     * que par lui, donc que la route du palier que le lecteur vérifie — ni
     * repository, ni classe de backoffice, ni provider, ni le rendu du corpus.
     * Un outil sans route ne reçoit que ce que sa liste lui accorde.
     */
    public function testEveryToolReceivesOnlyWhatItsKindAllows(): void
    {
        foreach ($this->registeredToolClasses() as $name => $class) {
            $expected = \array_key_exists($name, self::TOOLS_WITHOUT_ROUTE) ? self::TOOLS_WITHOUT_ROUTE[$name][1] : [RoutePayloadReaderInterface::class];

            self::assertSame($expected, $this->constructorTypesOf($class), \sprintf('« %s » reçoit d\'autres dépendances que celles de son espèce.', $name));
        }
    }

    /**
     * Le lecteur appelle le provider de la route sans requête HTTP ni jeton :
     * un provider qui lirait la requête ou l'appelant servirait par MCP autre
     * chose que sa route (relecture de la tâche 2). Aucun provider d'une route
     * servie n'en dépend.
     */
    public function testNoServedProviderDependsOnTheRequestOrTheCaller(): void
    {
        $factory = self::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        $checked = 0;

        foreach (array_filter($this->tools()) as $name => $declared) {
            foreach ($factory->create($declared->resourceClass) as $resource) {
                foreach ($resource->getOperations() ?? [] as $operation) {
                    $provider = $operation->getProvider();
                    if (\is_string($provider) && class_exists($provider)) {
                        self::assertSame([], $this->callerDependenciesOf($provider), \sprintf('Le provider de « %s » dépend de la requête ou de l\'appelant.', $name));
                        ++$checked;
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(\count(array_filter($this->tools())), $checked, 'Des providers n\'ont pas été résolus en classe : le test ne les vérifie pas.');
    }

    public function testAProviderReadingTheRequestOrTheCallerIsCaught(): void
    {
        self::assertSame([RequestStack::class, Security::class], $this->callerDependenciesOf(CallerReadingProvider::class));
    }

    /**
     * L'égalité outil/route (D1) n'est prouvée que pour les outils que les
     * tests d'égalité appellent : un outil adossé ajouté sans ses lignes
     * dans `RouteBackedToolsTest` échapperait à la preuve.
     */
    public function testEveryRouteBackedToolHasItsRouteEqualityTest(): void
    {
        $tested = array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(RouteBackedToolsTest::toolsAndTheirRoutes())));
        $tested = [...$tested, ...array_keys(self::EQUALITY_TESTED_APART)];
        $routeBacked = array_keys(array_filter($this->tools()));
        sort($routeBacked);
        sort($tested);

        self::assertSame($routeBacked, $tested);
    }

    /**
     * Sens 1. Les routes des paliers servis sans connexion que ni un outil (sa
     * route GET de contenu) ni une exclusion (à ses méthodes exactes) ne
     * couvrent.
     *
     * @param array<string, ?BackedByRoute> $tools
     *
     * @return list<string> signature « MÉTHODES chemin » de chaque route non couverte
     */
    private function uncoveredRoutes(RouteCollection $routes, array $tools): array
    {
        $exposedResources = array_values(array_map(static fn (BackedByRoute $route): string => $route->resourceClass, array_filter($tools)));

        $uncovered = [];
        foreach ($routes as $route) {
            if (!str_starts_with($route->getPath(), '/api/') || !\in_array($this->tierOf($route), self::TIERS_WITHOUT_CONNECTION, true)) {
                continue;
            }

            $readByATool = $this->isContentGetRoute($route) && \in_array($route->getDefault('_api_resource_class'), $exposedResources, true);
            if ($readByATool || \array_key_exists($this->signatureOf($route), self::EXCLUDED_ROUTES)) {
                continue;
            }

            $uncovered[] = $this->signatureOf($route);
        }
        sort($uncovered);

        return $uncovered;
    }

    /**
     * Sens 2. Les outils qui ne sont ni dans la liste fermée, ni adossés à une
     * route GET de contenu de leur ressource dont le palier est exactement
     * celui qu'ils déclarent — et un palier servi sans connexion.
     *
     * @param array<string, ?BackedByRoute> $tools
     *
     * @return list<string> noms des outils fautifs
     */
    private function toolsWithoutAMatchingRoute(RouteCollection $routes, array $tools): array
    {
        $faulty = [];
        foreach ($tools as $name => $declared) {
            $matches = null === $declared
                ? \array_key_exists($name, self::TOOLS_WITHOUT_ROUTE)
                : \in_array($declared->tier, self::TIERS_WITHOUT_CONNECTION, true) && $this->hasContentGetRouteOfTier($routes, $declared);

            if (!$matches) {
                $faulty[] = $name;
            }
        }
        sort($faulty);

        return $faulty;
    }

    private function hasContentGetRouteOfTier(RouteCollection $routes, BackedByRoute $declared): bool
    {
        foreach ($routes as $route) {
            if ($this->isContentGetRoute($route)
                && $declared->resourceClass === $route->getDefault('_api_resource_class')
                && $declared->tier === $this->tierOf($route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une route GET d'une opération API Platform réelle, pas une de celles
     * qu'API Platform synthétise pour construire ses IRI.
     */
    private function isContentGetRoute(Route $route): bool
    {
        return \in_array(Request::METHOD_GET, $route->getMethods(), true)
            && null !== $route->getDefault('_api_operation_name')
            && self::NOT_EXPOSED_CONTROLLER !== $route->getDefault('_controller');
    }

    /**
     * Le palier d'une route : celui de la première règle `access_control` de
     * son chemin, relevé au-delà de tout palier (`null`) quand son opération
     * API Platform porte un attribut `security`, que le lecteur refuse.
     */
    private function tierOf(Route $route): ?AccessTier
    {
        $accessMap = self::getContainer()->get('security.access_map');
        [$roles] = $accessMap->getPatterns(Request::create($route->getPath()));

        $tier = $this->tierOfRoles($roles ?? [], $route->getPath());

        return null !== $tier && $this->operationCarriesSecurity($route) ? null : $tier;
    }

    /**
     * @param array<mixed> $roles
     */
    private function tierOfRoles(array $roles, string $path): ?AccessTier
    {
        if ([] === $roles) {
            return AccessTier::ANONYMOUS;
        }

        if (1 !== \count($roles)) {
            throw UnreadableAccessRuleException::forPath($path, 'exige plusieurs rôles');
        }

        $role = array_first($roles);
        if (!\is_string($role)) {
            throw UnreadableAccessRuleException::forPath($path, 'n\'exige pas un rôle');
        }

        if (\in_array($role, self::ROLES_ABOVE_TIERS, true)) {
            return null;
        }

        return self::TIER_OF_ROLE[$role] ?? throw UnreadableAccessRuleException::forPath($path, 'exige un rôle inconnu ('.$role.')');
    }

    private function operationCarriesSecurity(Route $route): bool
    {
        $resourceClass = $route->getDefault('_api_resource_class');
        $operationName = $route->getDefault('_api_operation_name');
        if (!\is_string($resourceClass) || !\is_string($operationName) || !class_exists($resourceClass)) {
            return false;
        }

        try {
            $operation = self::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class)->create($resourceClass)->getOperation($operationName);
        } catch (OperationNotFoundException) {
            // Une route fictive des cas de ce test : pas d'opération réelle.
            return false;
        }

        return $operation instanceof HttpOperation && null !== $operation->getSecurity();
    }

    private function signatureOf(Route $route): string
    {
        $methods = $route->getMethods();

        return \sprintf('%s %s', [] === $methods ? 'ANY' : implode('|', $methods), $route->getPath());
    }

    /**
     * Une route d'opération API Platform, telle que son chargeur la déclare.
     *
     * @param list<string> $methods
     */
    private function contentRoute(string $path, string $resourceClass, array $methods): Route
    {
        return new Route($path, [
            '_api_resource_class' => $resourceClass,
            '_api_operation_name' => 'fictive',
            '_controller' => 'api_platform.symfony.main_controller',
        ], methods: $methods);
    }

    /**
     * @param class-string $class
     *
     * @return list<string> dépendances du constructeur qui lisent la requête ou l'appelant
     */
    private function callerDependenciesOf(string $class): array
    {
        return array_values(array_filter(
            $this->constructorTypesOf($class),
            static fn (string $type): bool => \in_array($type, self::CALLER_DEPENDENCIES, true),
        ));
    }

    /**
     * @param class-string $class
     *
     * @return list<string> types des paramètres du constructeur, dans l'ordre
     */
    private function constructorTypesOf(string $class): array
    {
        $types = [];
        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $types[] = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
        }

        return $types;
    }

    private function routes(): RouteCollection
    {
        // Une copie : les cas « fictifs » y ajoutent une route.
        return clone self::getContainer()->get('router')->getRouteCollection();
    }

    /**
     * Les outils du registre réel, avec la route que chacun déclare.
     *
     * @return array<string, ?BackedByRoute>
     */
    private function tools(): array
    {
        return $this->declaredToolRoutes();
    }
}
