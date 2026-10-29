<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Jwt;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Tests\Support\ReadsResponseCookies;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * #748 (F1, décision de l'auteur du 2026-10-29) : `BEARER` et `XSRF-TOKEN` sont
 * des cookies de session, que rien n'expire plus avec le JWT. Un cookie refusé
 * par le firewall `api` — JWT expiré, JWT invalide, session antérieure au
 * changement de mot de passe — resterait donc jusqu'à la fermeture du navigateur.
 * Une route qui exige un rôle le refuse (401) et **efface** les deux cookies : la
 * requête suivante est anonyme. Une route publique, elle, ne refuse plus rien
 * (R35, {@see \App\Tests\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionTest}) :
 * elle sert l'anonyme et laisse le cookie, que la première route protégée — le SPA
 * lit `/api/me` à chaque chargement — efface.
 *
 * Le refus d'une session antérieure au changement de mot de passe est le témoin
 * déjà vert : {@see \App\Tests\Security\Authentication\SessionBeforePasswordChangeTest}
 * (`testAClosedSessionIsServedAsAnonymousOnAPublicRouteAndClearedByTheFirstProtectedOne`).
 *
 * Les jetons expirés sont signés par l'encodeur du conteneur (la clé du site),
 * jamais par une clé écrite ici ; la valeur « invalide » est tirée au hasard.
 */
final class RefusedSessionCookiesAreClearedTest extends WebTestCase
{
    use ReadsResponseCookies;

    /** Une route qui exige un rôle (`ROLE_TRUSTED`) : l'anonyme y prend le 401, et un cookie refusé aussi. */
    private const string PROTECTED_ROUTE = '/api/me';

    /**
     * Une route publique (ApiRouteExposureTest::PUBLIC_PATHS), servie une fois le cookie effacé. Elle
     * répond 200 sur une table vide (ContributionResourceTest) : un 404, un 403 ou tout autre
     * code que le 200 ne passe pas pour « servie » (St3-12 de la troisième relecture).
     */
    private const string PUBLIC_ROUTE = '/api/contributions/fr';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    public function testAnExpiredJwtCookieIsClearedByItsFirst401(): void
    {
        $encoder = self::getContainer()->get(JWTEncoderInterface::class);
        $this->putTheCookie($encoder->encode([
            'username' => 'expired-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER'],
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]));

        $this->client->request('GET', self::PROTECTED_ROUTE);

        $this->assertRefusedAndBothCookiesCleared();
        $this->assertThePublicRouteIsServedAfterwards();
    }

    public function testAnInvalidJwtCookieIsClearedToo(): void
    {
        $this->putTheCookie(bin2hex(random_bytes(16)));

        $this->client->request('GET', self::PROTECTED_ROUTE);

        $this->assertRefusedAndBothCookiesCleared();
        $this->assertThePublicRouteIsServedAfterwards();
    }

    /** Témoin contre un effacement de trop : une session servie garde ses cookies. */
    public function testAServedSessionIsNotCleared(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        // Palier de base sur une route de confiance : 403 (authentifié, insuffisant), pas un 401.
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Une session servie ne doit rien effacer.');
    }

    private function assertRefusedAndBothCookiesCleared(): void
    {
        self::assertResponseStatusCodeSame(401);

        $cookies = $this->cookiesByName($this->client);
        $factory = self::getContainer()->get(AuthCookieFactory::class);
        foreach ([AuthCookieFactory::BEARER, AuthCookieFactory::XSRF_TOKEN] as $name) {
            self::assertArrayHasKey($name, $cookies, \sprintf('Le 401 doit effacer %s.', $name));
            self::assertTrue($cookies[$name]->isCleared(), \sprintf('%s doit être expiré.', $name));
            // Mêmes attributs que la pose : sinon le navigateur ne le supprimerait pas.
            self::assertSame($this->attributes($factory->expired($name)), $this->attributes($cookies[$name]));
        }
        self::assertNull($this->client->getCookieJar()->get(AuthCookieFactory::BEARER), 'Le navigateur garde le cookie refusé.');
    }

    /** Le navigateur n'envoie plus rien : la même requête est anonyme, donc plus un 401. */
    private function assertThePublicRouteIsServedAfterwards(): void
    {
        $this->client->request('GET', self::PUBLIC_ROUTE);

        // Seul le 200 prouve que la route a été atteinte et servie : un 401 est le cookie mort, et
        // tout autre code (un 403, un 404) laisserait passer un refus qui n'est pas celui-là.
        self::assertResponseStatusCodeSame(200, 'Le cookie effacé ne doit plus faire refuser la route publique.');
    }

    /** Pose ce JWT en `BEARER` dans le navigateur de test, comme un cookie de session le garderait. */
    private function putTheCookie(string $jwt): void
    {
        $this->client->getCookieJar()->set(new Cookie(AuthCookieFactory::BEARER, $jwt, null, '/', 'localhost', false, true, false, 'lax'));
    }
}
