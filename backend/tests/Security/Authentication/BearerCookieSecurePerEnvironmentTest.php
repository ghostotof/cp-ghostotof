<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;

/**
 * Le `BEARER` du login est posé par Lexik depuis lexik_jwt_authentication.yaml,
 * pas par {@see AuthCookieFactory} (issue #703, suivi de #659). Son attribut
 * `Secure` est la seule clé de Lexik qui diffère d'un environnement à
 * l'autre, et {@see \App\Tests\Security\SecurityConfigEnvironmentParityTest}
 * l'exempte pour cette raison ; mais
 * {@see \App\Tests\Security\Authentication\AuthCookieAttributesTest}, qui
 * aligne ce cookie sur la fabrique, ne tourne qu'en `test`. Rien n'épinglait
 * la valeur de `prod` (préproduction et production tournent en APP_ENV=prod) :
 * le bloc qui la posait retiré, la garde de parité demandait de retirer
 * l'exemption, et la production posait le JWT sans `Secure`.
 *
 * Depuis #714, `test` porte `Secure` comme `prod`, et c'est `dev` qui fait
 * exception : AuthCookieAttributesTest lit donc `Secure` sur la vraie chaîne
 * de pose (surcharge de service, décorateur, listener compris). Ce test-ci
 * reste le seul à voir `prod` et `dev`, que ne charge aucun test fonctionnel.
 *
 * Même lecture que la garde de parité ({@see EnvironmentConfigKernel}, sans
 * compilation), et même pas que
 * {@see \App\Tests\Security\Authentication\CpgUserPasswordHasherTest} pour
 * l'autre exemption : la valeur déclarée, écrite en clair, dans chaque
 * environnement que le noyau admet. La valeur attendue est celle de la
 * fabrique, qui suit la règle « `Secure` partout sauf en `dev` »
 * ({@see \App\Tests\Security\Authentication\Infrastructure\Http\AuthCookieFactoryTest}) :
 * un environnement ajouté demain est vérifié sans qu'on y pense.
 */
final class BearerCookieSecurePerEnvironmentTest extends TestCase
{
    /**
     * Un noyau par environnement, gardé d'un test à l'autre : chaque
     * chargement parcourt tout src/.
     *
     * @var array<string, EnvironmentConfigKernel>
     */
    private static array $kernels = [];

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
     * Les deux tests ci-dessus ne lisent que l'entrée `BEARER` : une seconde
     * entrée de `set_cookies` (`JWT_COPY: { secure: false }`) ferait poser au
     * login une copie du JWT sans `Secure`, rejouable sous le nom `BEARER`, et
     * la parité la verrait commune à tous les environnements. Un seul cookie
     * porte donc le jeton, celui que l'extracteur lit (audit de #703).
     */
    public function testTheJwtTravelsInTheBearerCookieOnly(): void
    {
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            $kernel = $this->kernel($environment);
            self::assertSame(
                [AuthCookieFactory::BEARER],
                array_keys($kernel->declaredMapping('lexik_jwt_authentication', 'set_cookies')),
                $environment.' : set_cookies ne doit déclarer que le cookie BEARER, dont la garde fige le `Secure`.',
            );
            self::assertSame(
                AuthCookieFactory::BEARER,
                $kernel->declaredMapping('lexik_jwt_authentication', 'token_extractors')['cookie']['name'] ?? null,
                $environment.' : l\'extracteur doit lire le cookie BEARER, le seul que la garde couvre.',
            );
        }
    }

    private function kernel(string $environment): EnvironmentConfigKernel
    {
        return self::$kernels[$environment] ??= new EnvironmentConfigKernel($environment);
    }

    /**
     * La valeur déclarée, sans défaut du bundle : une clé absente (`null`)
     * ne laisse pas Lexik décider à notre place.
     */
    private function declaredBearerSecure(string $environment): mixed
    {
        $bearer = $this->kernel($environment)->declaredMapping('lexik_jwt_authentication', 'set_cookies')[AuthCookieFactory::BEARER] ?? null;

        return \is_array($bearer) ? $bearer['secure'] ?? null : null;
    }
}
