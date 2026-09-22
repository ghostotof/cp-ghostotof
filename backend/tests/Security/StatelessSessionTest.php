<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;

/**
 * Audit 2026-09-16, constat A23 : `framework.session` était activée alors que
 * l'application est sans état de bout en bout — les deux firewalls sont
 * `stateless: true`, l'authentification tient dans un JWT en cookie, aucun
 * formulaire Symfony, aucun message flash, aucun `getSession()` dans `src/`.
 *
 * Ce n'est pas qu'une ligne inutile. Le gestionnaire de session par défaut
 * écrit des fichiers sous `%kernel.cache_dir%/sessions`, c'est-à-dire sous
 * `var/` — et les pods tournent en `readOnlyRootFilesystem` avec le seul
 * `var/log` monté (ADR 0005). Une session démarrée par mégarde en production
 * relèverait donc exactement de la classe de défaut de l'ADR : une écriture
 * qui échoue sans bruit. Session désactivée, l'erreur devient franche —
 * `SessionNotFoundException` —, visible en test et en développement bien
 * avant d'atteindre un pod en lecture seule.
 *
 * Ce test pince les deux versants : plus de session possible sur une requête
 * réelle, et aucune réponse de l'API ne pose de cookie de session, sur un
 * échantillon couvrant le public, le palier de base, le backoffice, le login
 * et le logout.
 */
final class StatelessSessionTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'stateless-super';

    /**
     * Les noms que poseraient les deux fabriques de stockage utilisables ici :
     * `PHPSESSID` (native, dev/préprod/prod) et `MOCKSESSID`
     * (`session.storage.factory.mock_file`, l'environnement de test).
     *
     * @var list<string>
     */
    private const array SESSION_COOKIE_NAMES = ['PHPSESSID', 'MOCKSESSID'];

    /**
     * Les services que `framework.session` enregistrerait : la fabrique de
     * session, celle du stockage, le gestionnaire de persistance.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_SESSION_SERVICE_IDS = [
        'session.factory',
        'session.storage.factory',
        'session.handler',
    ];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /**
     * L'assertion la plus robuste porte sur le comportement, et sur une requête
     * réellement traitée par le kernel — `RequestStack::getSession()` hors
     * requête lève déjà `SessionNotFoundException`, elle ne prouverait rien.
     * Celle-ci reste vraie quelle que soit la façon dont une version future de
     * Symfony câble la désactivation.
     */
    public function testARealRequestHasNoSessionToGive(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/about/fr');

        $request = $client->getRequest();
        self::assertFalse($request->hasSession(), 'Une session a été attachée à la requête.');

        $this->expectException(SessionNotFoundException::class);
        $request->getSession();
    }

    /**
     * Complément : plus aucune brique de session dans le conteneur compilé. Si
     * l'une réapparaissait, une session redeviendrait possible sans que
     * personne ne l'ait demandé.
     *
     * Les identifiants sont parcourus depuis une constante plutôt qu'écrits en
     * littéral dans l'appel : phpstan-symfony connaît le conteneur et réduirait
     * `has('session.factory')` à `false` à l'analyse, transformant l'assertion
     * en tautologie signalée comme telle. Le test doit rester une vérification
     * d'exécution.
     */
    public function testNoSessionServiceRemainsInTheContainer(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        foreach (self::FORBIDDEN_SESSION_SERVICE_IDS as $serviceId) {
            self::assertFalse(
                $container->has($serviceId),
                sprintf('Le service "%s" est de retour dans le conteneur : framework.session a été réactivée.', $serviceId),
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function provideRepresentativeRoutes(): iterable
    {
        // chemin, méthode, connexion ROLE_SUPER préalable
        yield 'public : GET /api/about/fr' => ['/api/about/fr', 'GET', false];
        yield 'palier de base : POST /api/account/base-access' => ['/api/account/base-access', 'POST', false];
        yield 'backoffice : GET /api/backoffice/users' => ['/api/backoffice/users', 'GET', true];
        yield 'login : POST /api/login_check' => ['/api/login_check', 'POST', false];
        yield 'logout : POST /api/logout' => ['/api/logout', 'POST', true];
    }

    #[DataProvider('provideRepresentativeRoutes')]
    public function testNoResponseEverSetsASessionCookie(string $path, string $method, bool $authenticated): void
    {
        $client = self::createClient();
        // Les quotas par IP vivent dans `cache.rate_limiter`, durable depuis
        // l'ADR 0005 : sans purge, /api/account/base-access finirait par
        // répondre 429 au fil des exécutions et le test mesurerait autre chose.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);

        if ($authenticated) {
            $this->logIn($client);
        }

        $client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::SUPER_USERNAME,
            'password' => TestCredentials::superPassword(),
        ]));

        $names = array_map(
            static fn (Cookie $cookie): string => $cookie->getName(),
            $client->getResponse()->headers->getCookies(),
        );

        foreach (self::SESSION_COOKIE_NAMES as $sessionCookie) {
            self::assertNotContains($sessionCookie, $names, sprintf('%s %s pose un cookie de session %s.', $method, $path, $sessionCookie));
        }

        // Et, en amont du cookie : aucune session n'a même été attachée à la
        // requête. Avec `framework.session` active, le SessionListener y posait
        // une fabrique paresseuse et cette assertion échouait.
        self::assertFalse(
            $client->getRequest()->hasSession(),
            sprintf('%s %s : une session a été attachée à la requête.', $method, $path),
        );
    }

    private function logIn(KernelBrowser $client): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::SUPER_USERNAME,
            'password' => TestCredentials::superPassword(),
        ]));
        self::assertResponseIsSuccessful();
    }
}
