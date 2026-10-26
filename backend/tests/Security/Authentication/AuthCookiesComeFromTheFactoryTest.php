<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Tests\Support\CookieConstructions;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * {@see AuthCookieFactory} est le seul endroit de `src/` qui construit un
 * cookie (issues #87 et #714) : ses attributs — `Secure` hors de `dev`,
 * `HttpOnly` sur le JWT, `SameSite=Lax`, `Path=/` sans `Domain` — ne valent
 * que si aucun site ne les recalcule. La règle n'était tenue que par la
 * relecture ; un futur site qui poserait le JWT, ou le jeton CSRF, par un
 * `Cookie::create()` à lui le ferait voyager sans `Secure`, suite verte, si ce
 * site n'est pas l'un des trois que compare
 * {@see \App\Tests\Security\Authentication\AuthCookieAttributesTest}.
 *
 * Le recensement ({@see CookieConstructions}) est éprouvé d'abord sur des
 * extraits littéraux : un recenseur qui ne trouverait jamais rien rendrait la
 * garde verte sans rien garder. Seule la fabrique est exemptée, et
 * l'exemption doit rester nécessaire.
 */
final class AuthCookiesComeFromTheFactoryTest extends TestCase
{
    public function testNoSourceFileBuildsACookieOutsideTheFactory(): void
    {
        $factorySite = $this->factoryPath().':';
        $outsideTheFactory = array_values(array_filter(
            CookieConstructions::in(RepositoryFiles::backend('src')),
            static fn (string $site): bool => !str_starts_with($site, $factorySite),
        ));

        self::assertSame(
            [],
            $outsideTheFactory,
            'Un cookie se construit par AuthCookieFactory, jamais en ligne : ses attributs (Secure, HttpOnly, SameSite) ne se recalculent pas ailleurs.',
        );
    }

    /**
     * Le jour où la fabrique ne construirait plus rien de ce que le
     * recensement voit, son exemption couvrirait un fichier quelconque — et
     * le recensement aurait sans doute cessé de reconnaître `Cookie::create()`.
     */
    public function testTheFactoryIsStillWhereCookiesAreBuilt(): void
    {
        self::assertNotSame(
            [],
            CookieConstructions::inCode(RepositoryFiles::read($this->factoryPath())),
            'AuthCookieFactory ne construit plus de cookie que le recensement reconnaisse : revoir l\'exemption ou le recensement.',
        );
    }

    /**
     * @param list<int> $expectedLines
     */
    #[DataProvider('snippets')]
    public function testTheCensusFindsEveryWayOfBuildingACookie(string $code, array $expectedLines): void
    {
        self::assertSame($expectedLines, CookieConstructions::inCode($code));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function snippets(): iterable
    {
        $use = "<?php\nnamespace App;\nuse Symfony\\Component\\HttpFoundation\\Cookie;\n";

        yield 'Cookie::create() importée' => [$use."\$c = Cookie::create('BEARER', \$jwt);\n", [4]];
        yield 'new Cookie importée' => [$use."\$c = new Cookie('BEARER', \$jwt);\n", [4]];
        yield 'Cookie::fromString()' => [$use."\$c = Cookie::fromString(\$header);\n", [4]];
        yield 'nom complet' => ["<?php\nnamespace App;\n\$c = \\Symfony\\Component\\HttpFoundation\\Cookie::create('x');\n", [3]];
        yield 'importée sous un alias' => ["<?php\nnamespace App;\nuse Symfony\\Component\\HttpFoundation\\Cookie as C;\n\$c = new C('x');\n", [4]];
        yield 'casse indifférente, comme PHP' => [$use."\$c = COOKIE::Create('x');\n", [4]];
        yield 'classe anonyme qui étend Cookie' => [$use."\$c = new class('x') extends Cookie {};\n", [4]];
        yield 'expiration en ligne' => ["<?php\nnamespace App;\n\$response->headers->clearCookie('BEARER');\n", [3]];
        yield 'expiration en ligne, appel nullsafe' => ["<?php\nnamespace App;\n\$response?->headers->clearCookie('BEARER');\n", [3]];
        yield 'retouche du Secure d\'un cookie de la fabrique' => ["<?php\nnamespace App;\n\$c = \$this->factory->bearer(\$jwt)->withSecure(false);\n", [3]];
        yield 'retouche de HttpOnly' => ["<?php\nnamespace App;\n\$c = \$cookie->withHttpOnly(false);\n", [3]];
        yield 'retouche de SameSite' => ["<?php\nnamespace App;\n\$c = \$cookie->withSameSite('none');\n", [3]];
        yield 'retouche du domaine' => ["<?php\nnamespace App;\n\$c = \$cookie->withDomain('.example.com');\n", [3]];
        yield 'fonction native setcookie()' => ["<?php\nnamespace App;\nsetcookie('BEARER', \$jwt);\n", [3]];
        yield 'fonction native qualifiée' => ["<?php\nnamespace App;\n\\setrawcookie('BEARER', \$jwt);\n", [3]];
        yield 'en-tête écrit à la main' => ["<?php\nnamespace App;\n\$response->headers->set('Set-Cookie', \$raw);\n", [3]];
        yield 'en-tête natif interpolé' => ["<?php\nnamespace App;\nheader(\"set-cookie: BEARER=\$jwt\");\n", [3]];
        yield 'plusieurs sites, dans l\'ordre' => [$use."\$a = Cookie::create('a');\n\$b = new Cookie('b');\n", [4, 5]];
        yield 'nom partiellement qualifié' => ["<?php\nnamespace App;\nuse Symfony\\Component\\HttpFoundation;\n\$c = HttpFoundation\\Cookie::create('x');\n", [4]];
        yield 'retouche de Partitioned' => ["<?php\nnamespace App;\n\$c = \$cookie->withPartitioned(false);\n", [3]];
        // Une sous-classe nommée porte ses propres défauts ; ses `new` et ses
        // appels statiques se résolvent ensuite vers elle, c'est donc sa
        // déclaration qui est refusée (relectures de #714).
        yield 'sous-classe nommée' => [$use."final class WeakCookie extends Cookie {}\n", [4]];
        yield 'sous-classe nommée, nom complet' => ["<?php\nnamespace App;\nclass WeakCookie extends \\Symfony\\Component\\HttpFoundation\\Cookie {}\n", [3]];
        // Le fournisseur de cookie de Lexik construit un Cookie aux attributs
        // passés en arguments, `secure` compris.
        yield 'fournisseur de Lexik appelé' => ["<?php\nnamespace App;\n\$c = \$this->bearerProvider->createCookie(\$jwt, secure: false);\n", [3]];
        yield 'fournisseur de Lexik instancié' => ["<?php\nnamespace App;\nuse Lexik\\Bundle\\JWTAuthenticationBundle\\Security\\Http\\Cookie\\JWTCookieProvider;\n\$p = new JWTCookieProvider('BEARER', 3600, 'lax', '/', null, false);\n", [4]];
        // HeaderBag normalise `_` en `-` et ignore la casse : `set_cookie`
        // pose un cookie comme `Set-Cookie`, y compris dans les en-têtes passés
        // au constructeur d'une réponse.
        yield 'en-tête au souligné' => ["<?php\nnamespace App;\n\$response->headers->set('set_cookie', \$raw);\n", [3]];
        yield 'en-têtes du constructeur d\'une réponse' => ["<?php\nnamespace App;\n\$r = new JsonResponse(\$data, 200, ['SET_COOKIE' => \$raw]);\n", [3]];
        yield 'en-tête écrit par échappements' => ["<?php\nnamespace App;\nheader(\"\\x53et-Cookie: BEARER=\$jwt\");\n", [3]];

        // Ce qui ne construit aucun cookie.
        yield 'poser un cookie de la fabrique' => ["<?php\nnamespace App;\n\$response->headers->setCookie(\$this->factory->bearer(\$jwt));\n", []];
        yield 'constante de la classe' => [$use."\$s = Cookie::SAMESITE_LAX;\n", []];
        yield 'simple mention' => [$use."\$class = Cookie::class;\n", []];
        yield 'homonyme de l\'espace de noms' => ["<?php\nnamespace App;\n\$c = Cookie::create('x');\n", []];
        yield 'cookie de BrowserKit' => ["<?php\nnamespace App;\nuse Symfony\\Component\\BrowserKit\\Cookie;\n\$c = new Cookie('x', 'y');\n", []];
        yield 'méthode homonyme déclarée' => ["<?php\nnamespace App;\nfinal class A { public function setcookie(): void {} }\n", []];
        yield 'méthode homonyme appelée' => ["<?php\nnamespace App;\n\$a->setcookie();\n", []];
        yield 'commentaire' => ["<?php\nnamespace App;\n// Cookie::create('x'), clearCookie(), Set-Cookie\n", []];
        yield 'autre en-tête' => ["<?php\nnamespace App;\n\$response->headers->set('Cache-Control', 'no-store');\n", []];
        // `withPath()` est aussi la méthode d'une URI PSR-7, qu'on ne renomme
        // pas (league/oauth2-server travaille en PSR-7) : seuls les attributs
        // de sécurité d'un cookie sont refusés par leur nom.
        yield 'URI PSR-7' => ["<?php\nnamespace App;\n\$uri = \$uri->withPath('/callback');\n", []];
        yield 'sous-classe d\'une autre classe' => ["<?php\nnamespace App;\nfinal class A extends B {}\n", []];
    }

    private function factoryPath(): string
    {
        $path = new ReflectionClass(AuthCookieFactory::class)->getFileName();
        self::assertIsString($path);

        return $path;
    }
}
