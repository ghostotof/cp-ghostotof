<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionListener;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\ConcreteApiUrl;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\RefusedBearerCookie;
use App\Tests\Support\TestCredentials;
use App\Tests\Support\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * #748 (C1 côté serveur, décision de l'auteur du 2026-10-29, R35) : une route que
 * l'`access_control` laisse à l'anonyme est **servie comme à l'anonyme** quel que
 * soit le cookie `BEARER` qu'elle reçoit. Le firewall `api` authentifie dès qu'un
 * cookie est présent (`JWTAuthenticator::supports()`) et rendait le 401 de Lexik
 * même sur « À propos » : {@see PublicRouteIgnoresRefusedSessionListener} rend la
 * main à la requête quand l'`AccessMap` n'exige rien.
 *
 * Une route publique **ne touche pas** au cookie refusé (rien n'est posé ni
 * effacé) : la première route protégée l'efface, et le SPA en lit une à chaque
 * chargement (`GET /api/me`). Les jetons expirés sont signés par l'encodeur du
 * conteneur (la clé du site), jamais par une clé écrite ici ; la valeur
 * « invalide » est tirée au hasard.
 */
final class PublicRouteIgnoresRefusedSessionTest extends WebTestCase
{
    use HttpJson;
    use ReadsSecurityAuditLog;
    use RefusedBearerCookie;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        TestDatabase::assertSafeToWipe($this->connection());
        $this->connection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnExpiredCookieOnAPublicReadIsServedAndKept(): void
    {
        $jwt = self::expiredJwt();
        self::putTheBearer($this->client, $jwt);

        $this->client->request('GET', self::PUBLIC_ROUTE);

        $this->assertServedWithoutTouchingTheCookie($jwt);
    }

    public function testAnInvalidCookieOnAPublicReadIsServedAndKept(): void
    {
        $jwt = bin2hex(random_bytes(16));
        self::putTheBearer($this->client, $jwt);

        $this->client->request('GET', self::PUBLIC_ROUTE);

        $this->assertServedWithoutTouchingTheCookie($jwt);
    }

    /** Le cookie gardé par la route publique tombe au premier 401 d'une route protégée, aux attributs de la pose. */
    public function testTheFirstProtectedRouteStillRefusesAndClears(): void
    {
        self::putTheBearer($this->client, self::expiredJwt());
        $this->client->request('GET', self::PUBLIC_ROUTE);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', self::PROTECTED_ROUTE);

        self::assertResponseStatusCodeSame(401);
        $this->assertBothCookiesExpiredLikeTheFactory($this->client);
    }

    /**
     * Le jeton d'un compte **supprimé** (C5-9) : signé par la clé du site et pas expiré, mais le
     * pare-feu ne retrouve plus le compte. C'est une autre cause de refus que l'expiration ou
     * la valeur invalide, par une autre voie de Lexik : même verdict, une route publique le sert
     * comme l'anonyme, la première route protégée l'efface.
     */
    public function testATokenOfADeletedAccountIsServedOnAPublicReadAndClearedByTheFirstProtectedRoute(): void
    {
        $account = $this->registerTrusted();
        $this->signIn();
        $jwt = $this->client->getCookieJar()->get(AuthCookieFactory::BEARER)?->getValue() ?? self::fail('Aucun cookie BEARER.');
        $this->connection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $account->getId()->toRfc4122()]);

        $this->client->request('GET', self::PUBLIC_ROUTE);
        $this->assertServedWithoutTouchingTheCookie($jwt);

        $this->client->request('GET', self::PROTECTED_ROUTE);
        self::assertResponseStatusCodeSame(401);
        $this->assertBothCookiesExpiredLikeTheFactory($this->client);
    }

    /**
     * Le chemin d'une requête est jugé sous sa forme canonique (`%61` est `a`), comme le font le
     * routeur et l'`AccessMap` : `/%61pi/…` n'échappe ni à l'indulgence de la route publique ni
     * au refus de la route protégée, et ne change pas l'effacement.
     */
    public function testAPercentEncodedPathIsJudgedLikeItsCanonicalForm(): void
    {
        $jwt = bin2hex(random_bytes(16));
        self::putTheBearer($this->client, $jwt);

        $this->client->request('GET', '/%61pi/contributions/fr');
        $this->assertServedWithoutTouchingTheCookie($jwt);

        $this->client->request('GET', '/%61pi/me');
        self::assertResponseStatusCodeSame(401);
        $this->assertBothCookiesExpiredLikeTheFactory($this->client);
    }

    /**
     * `/api/logout` n'a pas de règle d'`access_control` : l'`AccessMap` la tient pour
     * publique. Avec un cookie mort et son `XSRF-TOKEN` (un navigateur qui se
     * déconnecte après l'expiration), la déconnexion est servie et expire les deux
     * cookies — comme pour un anonyme, sans compte à l'audit.
     */
    public function testLogoutWithADeadCookieExpiresBothCookies(): void
    {
        $this->registerTrusted();
        $csrfToken = $this->signIn();
        self::putTheBearer($this->client, bin2hex(random_bytes(16)));

        $this->client->request('POST', '/api/logout', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        $cookies = $this->cookiesByName($this->client);
        foreach ([AuthCookieFactory::BEARER, AuthCookieFactory::XSRF_TOKEN] as $name) {
            self::assertArrayHasKey($name, $cookies, \sprintf('La déconnexion doit expirer %s.', $name));
            self::assertTrue($cookies[$name]->isCleared(), \sprintf('%s doit être expiré.', $name));
        }
        $lines = self::securityAuditEvents('logged-out');
        self::assertCount(1, $lines);
        self::assertSame('anonymous', $lines[0]['actor'] ?? null, 'Un cookie mort ne désigne aucun compte.');
        self::assertArrayNotHasKey('userId', $lines[0]);
    }

    /** Témoin : une session valide sur la même route publique reste authentifiée (le compte est à l'audit). */
    public function testAValidSessionOnAPublicRouteIsStillAuthenticated(): void
    {
        $account = $this->registerTrusted();
        $csrfToken = $this->signIn();

        $this->client->request('POST', '/api/logout', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        $lines = self::securityAuditEvents('logged-out');
        self::assertCount(1, $lines);
        self::assertSame($account->getId()->toRfc4122(), $lines[0]['userId'] ?? null);
    }

    /**
     * Le prédicat du listener est celui d'`AccessListener::supports()` (« cette route exige
     * quelque chose »), recopié : sur **chaque** route de `/api`, les deux verdicts
     * coïncident. Si Symfony change le sien, ce test le dit.
     */
    public function testThePredicateAgreesWithTheAccessListenerOnEveryRoute(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $router = $container->get('router');
        $accessListener = $container->get('security.access_listener');
        $listener = $container->get(PublicRouteIgnoresRefusedSessionListener::class);

        $seen = 0;
        $required = 0;
        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api')) {
                continue;
            }
            // Une URL concrète : l'`AccessMap` ne lit que le chemin, pas la ressource.
            $url = ConcreteApiUrl::of($path);
            $methods = $route->getMethods();
            foreach ([] === $methods ? ['GET'] : $methods as $method) {
                $request = Request::create($url, $method);
                $verdict = true === $accessListener->supports($request);
                self::assertSame($verdict, $listener->requiresAccess($request), \sprintf('%s %s : le prédicat diverge d\'AccessListener::supports().', $method, $path));
                ++$seen;
                $required += $verdict ? 1 : 0;
            }
        }

        self::assertGreaterThan(0, $required, 'Aucune route protégée vue : le test ne compare plus rien.');
        self::assertGreaterThan($required, $seen, 'Aucune route publique vue : le test ne compare plus rien.');
    }

    private function assertServedWithoutTouchingTheCookie(string $jwt): void
    {
        self::assertResponseStatusCodeSame(200, 'Un cookie refusé ne doit pas faire répondre 401 une route publique.');
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Une route publique ne pose ni n\'efface rien.');
        self::assertSame($jwt, $this->client->getCookieJar()->get(AuthCookieFactory::BEARER)?->getValue(), 'Le cookie refusé reste dans le navigateur.');
    }

    private function registerTrusted(): CpgUser
    {
        $registrar = self::getContainer()->get(CpgUserRegistrarInterface::class);

        return $registrar->register('jane', TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
    }

    /** Ouvre une vraie session et rend le jeton `XSRF-TOKEN` à renvoyer dans l'en-tête des écritures. */
    private function signIn(): string
    {
        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => 'jane',
            'password' => TestCredentials::plainPassword(),
        ]));
        self::assertResponseIsSuccessful();

        return $this->client->getCookieJar()->get(AuthCookieFactory::XSRF_TOKEN)?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
