<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Tests\Support\HttpJson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou transverse : **aucune route /api ne doit renvoyer de donnée à un
 * appelant qui n'y a pas droit**, indépendamment de ce que le frontend choisit
 * d'afficher. Le masquage côté client n'est jamais une protection.
 *
 * Contrairement aux tests par ressource (BackofficeStatResourceTest, etc.) qui
 * vérifient chacun *leurs* endpoints, ce test énumère le routeur : une route
 * ajoutée demain est automatiquement soumise à la règle. Pour qu'elle soit
 * servie sans authentification, il faut l'inscrire **explicitement** dans
 * PUBLIC_PATHS avec sa justification — un choix conscient, jamais un oubli.
 *
 * Trois invariants :
 *  1. toute route hors PUBLIC_PATHS refuse un appelant anonyme (401 ou 403) ;
 *  2. aucune route /api/backoffice ne peut être inscrite dans PUBLIC_PATHS ;
 *  3. tout le backoffice refuse un compte authentifié mais sans ROLE_SUPER
 *     (authentifié ≠ autorisé).
 */
final class ApiRouteExposureTest extends WebTestCase
{
    use HttpJson;

    private const string PLAIN_USERNAME = 'jane';
    private const string PLAIN_PASSWORD = 'SecurePassword123';

    /**
     * Routes délibérément servies sans authentification, chacune justifiée.
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array PUBLIC_PATHS = [
        // --- Contenu de vitrine, non identifiant, éditable au backoffice :
        // c'est à la saisie qu'on décide de ce qui est publié.
        '/api/about/{locale}' => 'Page « À propos » — publique dans son intégralité (audit C3).',
        '/api/quality/{locale}' => 'Principes/traits qualité — contenu de démonstration.',
        '/api/stats/{locale}' => 'Chiffres clés — contenu de démonstration.',
        '/api/experience/technologies' => 'Liste de technologies — contenu de démonstration.',

        // --- Parcours publics par conception.
        '/api/contact' => 'Formulaire de contact anonyme (honeypot + rate limit IP).',
        '/api/account/password-setup/{token}' => 'Définition du mot de passe via lien e-mail : l\'appelant n\'a pas encore de compte utilisable (rate limit IP).',
        '/api/login_check' => 'Point d\'entrée du login : par définition atteint sans être authentifié.',

        // --- Infrastructure API Platform. enable_docs/enable_entrypoint sont à
        // false sous when@prod (cf. api_platform.yaml) : ces routes n'existent
        // ni en préprod ni en prod, seulement en dev/test.
        '/api/docs.{_format}' => 'Schéma OpenAPI — désactivé en préprod/prod (when@prod).',
        '/api/{index}.{_format}' => 'Point d\'entrée Hydra — désactivé en préprod/prod (when@prod).',
        '/api/errors/{status}.{_format}' => 'Ressource d\'erreur interne d\'API Platform, aucune donnée métier.',
        '/api/validation_errors/{id}' => 'Ressource d\'erreur de validation interne d\'API Platform.',
        '/api/.well-known/genid/{id}' => 'Identifiants anonymes générés par API Platform, aucune donnée métier.',
        '/api/.well-known/genid/{id}.{_format}' => 'Idem, variante formatée.',
    ];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /**
     * Invariant n°1 — la règle de fond. Une route absente de PUBLIC_PATHS doit
     * être refusée à un anonyme : 401 (firewall JWT) pour les lectures, 403
     * (CsrfCookieRequestSubscriber, qui s'exécute au-dessus du firewall) pour
     * les méthodes d'écriture. Dans les deux cas : aucune donnée servie.
     */
    public function testEveryRouteOutsideThePublicAllowListRefusesAnonymousCallers(): void
    {
        $client = self::createClient();
        $routes = $this->protectedApiRoutes();

        self::assertNotEmpty($routes, 'Aucune route protégée détectée : le test ne vérifie plus rien.');

        foreach ($routes as [$path, $method]) {
            $client->request($method, $this->concreteUrl($path));

            self::assertContains(
                $client->getResponse()->getStatusCode(),
                [401, 403],
                sprintf(
                    '%s %s a répondu %d à un appelant anonyme. Soit la route doit être protégée, '
                    .'soit elle est publique et doit être inscrite dans PUBLIC_PATHS avec sa justification.',
                    $method,
                    $path,
                    $client->getResponse()->getStatusCode(),
                ),
            );
        }
    }

    /**
     * Invariant n°2 — garde structurel : on ne peut pas « faire taire » ce test
     * en inscrivant une route d'administration dans la liste blanche.
     */
    public function testNoBackofficeRouteCanBeAllowListedAsPublic(): void
    {
        foreach (array_keys(self::PUBLIC_PATHS) as $path) {
            self::assertStringStartsNotWith(
                '/api/backoffice',
                $path,
                'Une route de backoffice ne peut jamais être publique.',
            );
        }
    }

    /**
     * Garde d'hygiène : une entrée de PUBLIC_PATHS qui ne correspond plus à
     * aucune route est un reste de refactor — elle donnerait une fausse
     * impression de couverture.
     */
    public function testThePublicAllowListHasNoStaleEntry(): void
    {
        $declaredPaths = $this->allApiRoutePaths();

        foreach (array_keys(self::PUBLIC_PATHS) as $path) {
            self::assertContains(
                $path,
                $declaredPaths,
                sprintf('PUBLIC_PATHS déclare "%s", qui n\'existe plus dans le routeur.', $path),
            );
        }
    }

    /**
     * Invariant n°3 — authentifié ne veut pas dire autorisé. Un compte valide
     * mais sans ROLE_SUPER doit se voir refuser l'intégralité du backoffice.
     */
    public function testEveryBackofficeRouteRefusesAnAuthenticatedUserWithoutRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $routes = array_filter(
            $this->protectedApiRoutes(),
            static fn (array $route): bool => str_starts_with($route[0], '/api/backoffice'),
        );

        self::assertNotEmpty($routes, 'Aucune route de backoffice détectée.');

        foreach ($routes as [$path, $method]) {
            $client->request($method, $this->concreteUrl($path), server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_XSRF_TOKEN' => $csrfToken,
            ], content: self::jsonBody([]));

            self::assertSame(
                403,
                $client->getResponse()->getStatusCode(),
                sprintf('%s %s doit répondre 403 à un compte sans ROLE_SUPER.', $method, $path),
            );
        }
    }

    /**
     * Toutes les routes /api hors liste blanche, dédupliquées par (chemin, méthode).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function protectedApiRoutes(): array
    {
        $routes = [];

        foreach ($this->router()->getRouteCollection() as $route) {
            $path = $route->getPath();

            if (!str_starts_with($path, '/api') || isset(self::PUBLIC_PATHS[$path])) {
                continue;
            }

            foreach ($this->requestableMethods($route->getMethods()) as $method) {
                $routes[$path.' '.$method] = [$path, $method];
            }
        }

        return array_values($routes);
    }

    /**
     * @return list<string>
     */
    private function allApiRoutePaths(): array
    {
        $paths = [];

        foreach ($this->router()->getRouteCollection() as $route) {
            if (str_starts_with($route->getPath(), '/api')) {
                $paths[$route->getPath()] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * HEAD est écarté (doublon de GET) ; une route sans méthode déclarée les
     * accepte toutes, on la sonde en GET.
     *
     * @param array<string> $methods
     *
     * @return list<string>
     */
    private function requestableMethods(array $methods): array
    {
        $requestable = array_values(array_filter($methods, static fn (string $method): bool => 'HEAD' !== $method));

        return [] === $requestable ? ['GET'] : $requestable;
    }

    /**
     * Substitue des valeurs plausibles aux paramètres d'URL : la ressource
     * visée n'a pas besoin d'exister, le contrôle d'accès s'applique bien avant
     * que le provider ne cherche quoi que ce soit.
     */
    private function concreteUrl(string $path): string
    {
        $url = str_replace(['.{_format}', '{_format}'], '', $path);

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $matches): string => match ($matches[1]) {
                'locale' => 'fr',
                'token' => str_repeat('a', 64),
                'status' => '404',
                'index' => 'index',
                default => '1',
            },
            $url,
        );
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get('router');
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
