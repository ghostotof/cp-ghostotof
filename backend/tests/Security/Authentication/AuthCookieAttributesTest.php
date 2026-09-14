<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Issue #87 : les cookies BEARER et XSRF-TOKEN sont posés par trois réponses
 * (login, palier de base, logout) et lus par un seul navigateur. Un attribut
 * qui diverge entre la pose et l'expiration laisse le cookie en place ; un
 * `Secure` oublié sur un site le fait voyager en clair. Ce test relit les
 * `Set-Cookie` réels des trois réponses et exige, nom par nom, les mêmes
 * `Path`, `Secure`, `HttpOnly` et `SameSite` — ceux d'AuthCookieFactory.
 *
 * Le BEARER du login est posé par Lexik depuis lexik_jwt_authentication.yaml,
 * pas par la fabrique : c'est précisément ce site-là que ce test tient aligné.
 */
final class AuthCookieAttributesTest extends WebTestCase
{
    use HttpJson;

    private const string USERNAME = 'cookie-attributes';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testLoginBaseAccessAndLogoutSetTheSameAttributesPerCookieName(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);

        // 1. Palier de base (BaseAccessController) : BEARER + XSRF-TOKEN posés à la main.
        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
        $baseAccess = $this->cookiesByName($client);

        // 2. Login (Lexik pour BEARER, LoginSuccessSubscriber pour XSRF-TOKEN).
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::USERNAME,
            'password' => TestCredentials::plainPassword(),
        ]));
        self::assertResponseIsSuccessful();
        $login = $this->cookiesByName($client);

        // 3. Logout (CookieLogoutListener) : les deux expirés.
        $csrfCookie = $client->getCookieJar()->get(AuthCookieFactory::XSRF_TOKEN);
        self::assertNotNull($csrfCookie);
        $client->request('POST', '/api/logout', server: ['HTTP_X_XSRF_TOKEN' => $csrfCookie->getValue()]);
        self::assertResponseStatusCodeSame(204);
        $logout = $this->cookiesByName($client);

        foreach ([AuthCookieFactory::BEARER, AuthCookieFactory::XSRF_TOKEN] as $name) {
            self::assertArrayHasKey($name, $baseAccess, sprintf('%s absent de la réponse du palier de base.', $name));
            self::assertArrayHasKey($name, $login, sprintf('%s absent de la réponse de login.', $name));
            self::assertArrayHasKey($name, $logout, sprintf('%s absent de la réponse de logout.', $name));

            $expected = $this->attributes($baseAccess[$name]);
            self::assertSame($expected, $this->attributes($login[$name]), sprintf('%s : le login pose d\'autres attributs que le palier de base.', $name));
            self::assertSame($expected, $this->attributes($logout[$name]), sprintf('%s : le logout expire avec d\'autres attributs que la pose — le navigateur ne le supprimerait pas.', $name));

            self::assertTrue($logout[$name]->isCleared(), sprintf('%s : le logout doit expirer le cookie.', $name));
            self::assertFalse($baseAccess[$name]->isCleared());
            self::assertFalse($login[$name]->isCleared());
        }

        // Les invariants eux-mêmes, pour qu'une dérive commune aux trois sites
        // (par ex. un Path changé partout) ne passe pas inaperçue.
        self::assertTrue($baseAccess[AuthCookieFactory::BEARER]->isHttpOnly());
        self::assertFalse($baseAccess[AuthCookieFactory::XSRF_TOKEN]->isHttpOnly());
        self::assertSame('/', $baseAccess[AuthCookieFactory::BEARER]->getPath());
        self::assertSame(Cookie::SAMESITE_LAX, $baseAccess[AuthCookieFactory::BEARER]->getSameSite());
        // Env test, servi en http : Secure est faux ici et vrai en prod
        // (AuthCookieFactoryTest le pince ; lexik_jwt_authentication.yaml suit
        // par `when@prod`).
        self::assertFalse($baseAccess[AuthCookieFactory::BEARER]->isSecure());
    }

    /**
     * @return array<string, Cookie>
     */
    private function cookiesByName(KernelBrowser $client): array
    {
        $cookies = [];
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    /**
     * Les attributs qui doivent coïncider entre pose et expiration. La valeur
     * et l'échéance en sont exclus par nature.
     *
     * @return array{path: string, domain: string|null, secure: bool, httpOnly: bool, sameSite: string|null}
     */
    private function attributes(Cookie $cookie): array
    {
        return [
            'path' => $cookie->getPath(),
            'domain' => $cookie->getDomain(),
            'secure' => $cookie->isSecure(),
            'httpOnly' => $cookie->isHttpOnly(),
            'sameSite' => $cookie->getSameSite(),
        ];
    }
}
