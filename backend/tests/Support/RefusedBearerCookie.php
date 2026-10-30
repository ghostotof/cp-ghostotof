<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Le cookie `BEARER` refusé des tests de session morte (#748, St5-6), écrit une fois :
 * le JWT expiré, sa pose dans le navigateur de test, les deux routes qui servent de témoins et
 * la vérification que la réponse efface les deux cookies. Quatre tests le refaisaient chacun.
 *
 * Le JWT expiré est signé par l'encodeur du conteneur (la clé du site), jamais par une clé écrite
 * ici ; une valeur « invalide » se tire au hasard (`bin2hex(random_bytes(16))`) à l'endroit où
 * on la veut.
 *
 * Les deux routes sont `protected` et non `private` : une classe qui n'en lit qu'une (ou aucune, comme
 * `JwtClockSkewTest`) ne se voit pas reprocher par PHPStan une constante inutilisée.
 *
 * Une classe de test qui l'utilise lit aussi les cookies de la réponse ({@see ReadsResponseCookies}) :
 * le trait l'amène.
 */
trait RefusedBearerCookie
{
    use ReadsResponseCookies;

    /** Une route qui exige un rôle (`ROLE_TRUSTED`) : l'anonyme y prend le 401, et un cookie refusé aussi. */
    protected const string PROTECTED_ROUTE = '/api/me';

    /**
     * Une route publique (ApiRouteExposureTest::PUBLIC_PATHS), servie une fois le cookie effacé. Elle
     * répond 200 sur une table vide (ContributionResourceTest) : un 404, un 403 ou tout autre
     * code que le 200 ne passe pas pour « servie » (St3-12 de la troisième relecture).
     */
    protected const string PUBLIC_ROUTE = '/api/contributions/fr';

    /** Un JWT signé par la clé du site, expiré depuis une heure. */
    private static function expiredJwt(): string
    {
        return self::getContainer()->get(JWTEncoderInterface::class)->encode([
            'username' => 'expired-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER'],
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]);
    }

    /** Pose ce JWT en `BEARER` dans le navigateur de test, comme un cookie de session le garderait. */
    private static function putTheBearer(KernelBrowser $client, string $jwt): void
    {
        $client->getCookieJar()->set(new Cookie(AuthCookieFactory::BEARER, $jwt, null, '/', 'localhost', false, true, false, 'lax'));
    }

    /**
     * Les deux cookies d'auth de la réponse sont expirés, aux attributs exacts de la fabrique
     * (sinon le navigateur ne les supprimerait pas), et le navigateur ne garde plus le `BEARER`.
     */
    private function assertBothCookiesExpiredLikeTheFactory(KernelBrowser $client): void
    {
        $cookies = $this->cookiesByName($client);
        $factory = self::getContainer()->get(AuthCookieFactory::class);
        foreach ([AuthCookieFactory::BEARER, AuthCookieFactory::XSRF_TOKEN] as $name) {
            self::assertArrayHasKey($name, $cookies, \sprintf('Le 401 doit effacer %s.', $name));
            self::assertTrue($cookies[$name]->isCleared(), \sprintf('%s doit être expiré.', $name));
            self::assertSame($this->attributes($factory->expired($name)), $this->attributes($cookies[$name]));
        }
        self::assertNull($client->getCookieJar()->get(AuthCookieFactory::BEARER), 'Le navigateur garde le cookie refusé.');
    }
}
