<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\UnknownAuthCookieException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Issue #87 : les attributs des deux cookies d'authentification (BEARER,
 * XSRF-TOKEN) sont fixés en un seul endroit. Ce test pince chacun d'eux —
 * un site qui oublierait `Secure` ou `SameSite` ne peut plus exister,
 * puisqu'aucun site ne les écrit plus.
 */
final class AuthCookieFactoryTest extends TestCase
{
    public function testBearerIsHttpOnlyLaxOnRootPath(): void
    {
        $cookie = (new AuthCookieFactory('prod'))->bearer('jwt-value');

        self::assertSame(AuthCookieFactory::BEARER, $cookie->getName());
        self::assertSame('jwt-value', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly(), 'Le JWT ne doit jamais être lisible en JS.');
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function testXsrfIsReadableByJavaScriptLaxOnRootPath(): void
    {
        $cookie = (new AuthCookieFactory('prod'))->xsrf('csrf-value');

        self::assertSame(AuthCookieFactory::XSRF_TOKEN, $cookie->getName());
        self::assertSame('csrf-value', $cookie->getValue());
        self::assertFalse($cookie->isHttpOnly(), 'Le frontend doit pouvoir relire le jeton CSRF (double-submit).');
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    /**
     * `Secure` vrai si et seulement si l'environnement est `prod` : le dev
     * tourne en http (localhost:8080 sans TLS), un cookie Secure n'y serait
     * jamais renvoyé par le navigateur.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function provideEnvironments(): iterable
    {
        yield 'prod' => ['prod', true];
        yield 'dev' => ['dev', false];
        yield 'test' => ['test', false];
    }

    #[DataProvider('provideEnvironments')]
    public function testSecureFollowsTheEnvironmentOnEveryCookie(string $environment, bool $expectedSecure): void
    {
        $factory = new AuthCookieFactory($environment);

        self::assertSame($expectedSecure, $factory->bearer('jwt')->isSecure());
        self::assertSame($expectedSecure, $factory->xsrf('csrf')->isSecure());
        self::assertSame($expectedSecure, $factory->expired(AuthCookieFactory::BEARER)->isSecure());
        self::assertSame($expectedSecure, $factory->expired(AuthCookieFactory::XSRF_TOKEN)->isSecure());
    }

    public function testWithoutExpiryTheCookieIsASessionCookie(): void
    {
        $factory = new AuthCookieFactory('test');

        self::assertSame(0, $factory->bearer('jwt')->getExpiresTime());
        self::assertSame(0, $factory->xsrf('csrf')->getExpiresTime());
    }

    public function testExpiryIsHonoured(): void
    {
        $factory = new AuthCookieFactory('test');
        $expiresAt = time() + 900;

        self::assertSame($expiresAt, $factory->bearer('jwt', $expiresAt)->getExpiresTime());
        self::assertSame($expiresAt, $factory->xsrf('csrf', $expiresAt)->getExpiresTime());
    }

    /**
     * Un cookie expiré doit porter exactement les attributs de sa pose, sinon
     * le navigateur ne le supprime pas (c'est le drift que #87 vise) : même
     * chemin, même SameSite, même HttpOnly par nom, valeur vide, échéance
     * dans le passé.
     */
    public function testExpiredBearerMirrorsTheIssuedOne(): void
    {
        $factory = new AuthCookieFactory('prod');
        $issued = $factory->bearer('jwt');
        $expired = $factory->expired(AuthCookieFactory::BEARER);

        self::assertSame($issued->getName(), $expired->getName());
        self::assertSame($issued->getPath(), $expired->getPath());
        self::assertSame($issued->getDomain(), $expired->getDomain());
        self::assertSame($issued->getSameSite(), $expired->getSameSite());
        self::assertSame($issued->isHttpOnly(), $expired->isHttpOnly());
        self::assertSame($issued->isSecure(), $expired->isSecure());
        self::assertTrue($expired->isCleared());
    }

    public function testExpiredXsrfMirrorsTheIssuedOne(): void
    {
        $factory = new AuthCookieFactory('prod');
        $issued = $factory->xsrf('csrf');
        $expired = $factory->expired(AuthCookieFactory::XSRF_TOKEN);

        self::assertSame($issued->getName(), $expired->getName());
        self::assertSame($issued->getPath(), $expired->getPath());
        self::assertSame($issued->getDomain(), $expired->getDomain());
        self::assertSame($issued->getSameSite(), $expired->getSameSite());
        self::assertSame($issued->isHttpOnly(), $expired->isHttpOnly());
        self::assertSame($issued->isSecure(), $expired->isSecure());
        self::assertTrue($expired->isCleared());
    }

    public function testExpiringAnUnknownCookieNameIsRefused(): void
    {
        $this->expectException(UnknownAuthCookieException::class);
        $this->expectExceptionMessage('"PHPSESSID"');

        (new AuthCookieFactory('test'))->expired('PHPSESSID');
    }

    /**
     * Un nom construit dynamiquement peut venir d'une requête : il n'est cité
     * que s'il a la forme d'un nom de cookie, et jamais au-delà d'une longueur
     * bornée, pour qu'aucune valeur cliente arbitraire n'atteigne les journaux.
     *
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNotQuoted(): iterable
    {
        yield 'retour à la ligne' => ["BEARER\nX-Injected: 1"];
        // Sans \z, le `$` de PCRE accepte aussi la position avant un \n final.
        yield 'retour à la ligne final' => ["PHPSESSID\n"];
        yield 'espace' => ['BEARER x'];
        yield 'trop long' => [str_repeat('A', 65)];
        yield 'vide' => [''];
    }

    #[DataProvider('namesThatAreNotQuoted')]
    public function testAnUnknownNameIsQuotedOnlyWhenItLooksLikeACookieName(string $name): void
    {
        self::assertSame(
            '"<invalide>" n\'est pas un cookie d\'authentification (attendu : BEARER ou XSRF-TOKEN).',
            UnknownAuthCookieException::forName($name)->getMessage(),
        );
    }
}
