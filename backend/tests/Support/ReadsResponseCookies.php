<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Les cookies que pose la dernière réponse d'un client de test, lus sur la
 * réponse elle-même et non dans le `CookieJar` : BrowserKit perd le drapeau
 * `secure` d'un cookie reçu en http ({@see \Symfony\Component\BrowserKit\Cookie::fromString()}).
 *
 * Partagé par {@see \App\Tests\Security\Authentication\AuthCookieAttributesTest},
 * qui compare les trois sites de pose entre eux, et
 * {@see \App\Tests\Security\ApiRouteExposureTest}, qui exige de chaque réponse
 * qu'elle ne pose que des cookies de la fabrique (issue #714).
 */
trait ReadsResponseCookies
{
    /**
     * Un seul cookie par nom (relectures de #714) : un second `BEARER` sur un
     * autre `Path` ou `Domain` serait gardé par le navigateur à côté du
     * premier, et, indexé par nom, l'un des deux échapperait à la comparaison.
     *
     * @return array<string, Cookie>
     */
    private function cookiesByName(KernelBrowser $client): array
    {
        $cookies = [];
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            self::assertArrayNotHasKey(
                $cookie->getName(),
                $cookies,
                \sprintf('%s posé deux fois par la même réponse : le navigateur garderait les deux.', $cookie->getName()),
            );
            $cookies[$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    /**
     * Les attributs qui doivent coïncider entre pose et expiration. La valeur
     * et l'échéance en sont exclus par nature.
     *
     * @return array{path: string, domain: string|null, secure: bool, httpOnly: bool, sameSite: string|null, partitioned: bool}
     */
    private function attributes(Cookie $cookie): array
    {
        return [
            'path' => $cookie->getPath(),
            'domain' => $cookie->getDomain(),
            'secure' => $cookie->isSecure(),
            'httpOnly' => $cookie->isHttpOnly(),
            'sameSite' => $cookie->getSameSite(),
            'partitioned' => $cookie->isPartitioned(),
        ];
    }

    /**
     * La garde à l'exécution de #714 : la dernière réponse ne pose que
     * `BEARER` et `XSRF-TOKEN`, chacun avec les attributs exacts
     * d'{@see AuthCookieFactory} dans l'environnement du noyau. Elle voit ce
     * qu'aucun recensement des sources ne voit — un cookie posé par le vendor
     * (Lexik, le SDK MCP et son pont PSR-7), par la configuration ou par un
     * appel dynamique —, sur les seules réponses que le test produit.
     */
    private function assertOnlyFactoryCookies(KernelBrowser $client, string $request): void
    {
        $factory = new AuthCookieFactory($client->getKernel()->getEnvironment());
        foreach ($this->cookiesByName($client) as $name => $cookie) {
            self::assertContains(
                $name,
                [AuthCookieFactory::BEARER, AuthCookieFactory::XSRF_TOKEN],
                \sprintf('%s a posé le cookie « %s », qu\'AuthCookieFactory ne construit pas.', $request, $name),
            );
            self::assertSame(
                $this->attributes($factory->expired($name)),
                $this->attributes($cookie),
                \sprintf('%s a posé %s avec d\'autres attributs que ceux d\'AuthCookieFactory.', $request, $name),
            );
        }
    }
}
