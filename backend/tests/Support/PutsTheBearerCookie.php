<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * La pose d'un JWT en cookie `BEARER` dans le navigateur de test (#748, St6-6), écrite une fois.
 *
 * Les attributs sont **fixes** (`Path=/`, `HttpOnly`, `SameSite=Lax`, sans `Secure`) : le trait ne lit
 * rien du conteneur et ne demande donc aucun parent, ce qui le laisse à une classe qui n'a besoin que de
 * cette pose ({@see \App\Tests\Security\Authentication\Infrastructure\Jwt\JwtClockSkewTest}).
 * {@see RefusedBearerCookie} l'amène avec le reste de l'aide des sessions mortes.
 *
 * `SessionBeforePasswordChangeTest::replaceTheSignedInBearer()` fait autre chose, et porte un autre nom :
 * il recopie les attributs du cookie que la vraie connexion vient de poser.
 */
trait PutsTheBearerCookie
{
    /** Pose ce JWT en `BEARER` dans le navigateur de test, comme un cookie de session le garderait. */
    private static function putTheBearer(KernelBrowser $client, string $jwt): void
    {
        $client->getCookieJar()->set(new Cookie(AuthCookieFactory::BEARER, $jwt, null, '/', 'localhost', false, true, false, 'lax'));
    }
}
