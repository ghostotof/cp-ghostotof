<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;

/**
 * Le `BEARER` du login est posé par Lexik depuis lexik_jwt_authentication.yaml,
 * pas par AuthCookieFactory (issue #703, suivi de #659). Son attribut `Secure`
 * est la seule clé de Lexik qui diffère d'un environnement à l'autre, et
 * SecurityConfigEnvironmentParityTest l'exempte pour cette raison ; mais
 * AuthCookieAttributesTest, qui aligne ce cookie sur la fabrique, ne tourne
 * qu'en `test`, où `Secure` est faux. Rien n'épinglait la valeur de `prod`
 * (préproduction et production tournent en APP_ENV=prod) : le bloc `when@prod`
 * retiré, la garde de parité demandait de retirer l'exemption, et la
 * production posait le JWT sans `Secure`.
 *
 * Même lecture que la garde de parité (EnvironmentConfigKernel, sans
 * compilation), et même pas que CpgUserPasswordHasherTest pour l'autre
 * exemption : la valeur déclarée, écrite en clair, dans chaque environnement
 * que le noyau admet. La valeur attendue est celle de la fabrique, qui suit
 * la règle « `Secure` si et seulement si `prod` » (AuthCookieFactoryTest) :
 * un environnement ajouté demain est vérifié sans qu'on y pense.
 */
final class BearerCookieSecurePerEnvironmentTest extends TestCase
{
    public function testEveryEnvironmentDeclaresTheSecureAttributeOfTheFactory(): void
    {
        $declared = [];
        $expected = [];
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            $declared[$environment] = $this->declaredBearerSecure($environment);
            $expected[$environment] = new AuthCookieFactory($environment)->bearer('jwt')->isSecure();
        }

        self::assertSame(
            $expected,
            $declared,
            'lexik_jwt_authentication.set_cookies.BEARER.secure ne suit plus AuthCookieFactory : le JWT posé au login n\'a pas le `Secure` des autres sites.',
        );
    }

    /**
     * La raison d'être de la garde, écrite sans passer par la fabrique : si
     * sa règle changeait de nom d'environnement, le test ci-dessus resterait
     * vert sur un `Secure` faux partout.
     */
    public function testProductionDeclaresTheBearerCookieSecure(): void
    {
        self::assertTrue(
            $this->declaredBearerSecure('prod'),
            'Le cookie BEARER de prod (préproduction et production) doit être déclaré `secure: true` : sans lui, le JWT voyage en clair sur un réseau.',
        );
    }

    /**
     * La valeur déclarée, sans défaut du bundle : une clé absente (`null`)
     * ne laisse pas Lexik décider à notre place.
     */
    private function declaredBearerSecure(string $environment): mixed
    {
        $cookies = new EnvironmentConfigKernel($environment)->declaredMapping('lexik_jwt_authentication', 'set_cookies');
        $bearer = $cookies[AuthCookieFactory::BEARER] ?? null;

        return \is_array($bearer) ? $bearer['secure'] ?? null : null;
    }
}
