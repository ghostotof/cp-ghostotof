<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Jwt;

use App\Tests\Support\RefusedBearerCookie;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
    use RefusedBearerCookie;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    public function testAnExpiredJwtCookieIsClearedByItsFirst401(): void
    {
        self::putTheBearer($this->client, self::expiredJwt());

        $this->client->request('GET', self::PROTECTED_ROUTE);

        $this->assertRefusedAndBothCookiesCleared();
        $this->assertThePublicRouteIsServedAfterwards();
    }

    public function testAnInvalidJwtCookieIsClearedToo(): void
    {
        self::putTheBearer($this->client, bin2hex(random_bytes(16)));

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
        $this->assertBothCookiesExpiredLikeTheFactory($this->client);
    }

    /** Le navigateur n'envoie plus rien : la même requête est anonyme, donc plus un 401. */
    private function assertThePublicRouteIsServedAfterwards(): void
    {
        $this->client->request('GET', self::PUBLIC_ROUTE);

        // Seul le 200 prouve que la route a été atteinte et servie : un 401 est le cookie mort, et
        // tout autre code (un 403, un 404) laisserait passer un refus qui n'est pas celui-là.
        self::assertResponseStatusCodeSame(200, 'Le cookie effacé ne doit plus faire refuser la route publique.');
    }
}
