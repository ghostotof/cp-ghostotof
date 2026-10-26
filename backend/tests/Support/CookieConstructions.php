<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense, d'après les jetons des sources, chaque site qui construit ou
 * retouche un cookie (issue #714) — ce que seule
 * {@see \App\Security\Authentication\Infrastructure\Http\AuthCookieFactory}
 * doit faire dans `src/`, cf.
 * {@see \App\Tests\Security\Authentication\AuthCookiesComeFromTheFactoryTest} :
 *
 * - `new` et tout appel statique (`Cookie::create()`, `Cookie::fromString()`)
 *   de la classe de cookie de HttpFoundation ou du fournisseur de cookie de
 *   Lexik, et toute classe, nommée ou anonyme, qui étend l'une d'elles — ses
 *   `new` se résolvant ensuite vers elle, c'est sa déclaration qui compte. Les
 *   noms se résolvent comme PHP les résout ({@see ClassNameResolver} : import,
 *   alias, casse, nom partiellement qualifié, homonyme de l'espace de noms
 *   courant) ;
 * - `->clearCookie(…)`, qui construit le cookie d'expiration avec ses propres
 *   attributs, et `->createCookie(…)`, le fournisseur de Lexik, aux attributs
 *   passés en arguments ;
 * - `->withSecure()`, `->withHttpOnly()`, `->withSameSite()`,
 *   `->withPartitioned()`, `->withDomain()` : la retouche d'un attribut de
 *   sécurité d'un cookie déjà construit, celui de la fabrique compris. Pas
 *   `withPath()` ni `withRaw()` : le premier est aussi la méthode d'une URI
 *   PSR-7, que le nom seul ne distingue pas, et aucun des deux ne retire une
 *   protection ;
 * - les fonctions natives `setcookie()` et `setrawcookie()` ;
 * - une chaîne qui commence par l'en-tête `Set-Cookie`, normalisée comme
 *   `HeaderBag` normalise un nom (casse, `_` pour `-`) et ses échappements
 *   décodés quand elle est entre guillemets doubles : l'en-tête écrit à la
 *   main (`header()`, `$headers->set()`, les en-têtes d'un constructeur de
 *   réponse). Y compris pour le lire ou le retirer : `ResponseHeaderBag`
 *   offre `getCookies()` et `removeCookie()` pour cela.
 *
 * Non vus, limite assumée : un appel dynamique (`$cookie->$method()`,
 * `call_user_func()`), l'en-tête composé (`'Set-'.'Cookie'`, `sprintf()`) ou
 * écrit par un échappement `\u{…}`, un import groupé ou un `use function`
 * — refusés dans `src/` par {@see UnsortedImports} —, et ce que fait le code
 * du vendor ou la configuration : le cookie du login de Lexik, que
 * {@see \App\Tests\Security\Authentication\AuthCookieAttributesTest} lit sur
 * la réponse, et ce que voit la garde à l'exécution de
 * {@see \App\Tests\Security\ApiRouteExposureTest}. Une méthode `with…()` d'une
 * autre classe homonyme des cinq ci-dessus est vue à tort : la renommer.
 */
final class CookieConstructions
{
    /** Les classes qui construisent un cookie, en minuscules sans `\` initial. */
    private const array COOKIE_CLASSES = [
        'symfony\\component\\httpfoundation\\cookie',
        'lexik\\bundle\\jwtauthenticationbundle\\security\\http\\cookie\\jwtcookieprovider',
    ];

    /** Méthodes d'instance qui construisent un cookie ou en retouchent un attribut de sécurité, en minuscules. */
    private const array COOKIE_METHODS = [
        'clearcookie',
        'createcookie',
        'withsecure',
        'withhttponly',
        'withsamesite',
        'withpartitioned',
        'withdomain',
    ];

    /** Fonctions natives qui posent un cookie, en minuscules. */
    private const array COOKIE_FUNCTIONS = ['setcookie', 'setrawcookie'];

    /** Ce qui, avant un nom suivi de `(`, en fait autre chose qu'un appel de fonction. */
    private const array NOT_A_FUNCTION_CALL = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW, \T_CONST];

    /** Le nom de l'en-tête tel que HeaderBag le normalise. */
    private const string SET_COOKIE_HEADER = 'set-cookie';

    /**
     * @return list<string> `chemin:ligne` de chaque site, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        return PhpSources::sites($directory, self::inCode(...));
    }

    /**
     * @return list<int> lignes des sites, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $lines = [];
        foreach (ClassNameResolver::scopes($tokens) as $index => [$namespace, $imports]) {
            if (self::buildsACookie($tokens, $index, $namespace, $imports)) {
                $lines[] = $tokens[$index]->line;
            }
        }

        return $lines;
    }

    /**
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     */
    private static function buildsACookie(array $tokens, int $index, string $namespace, array $imports): bool
    {
        $token = $tokens[$index];
        $next = $tokens[$index + 1] ?? null;

        // `new X`, `extends X` : une classe anonyme est vue par son `extends`,
        // une seule fois.
        if ($token->is([\T_NEW, \T_EXTENDS])) {
            return null !== $next && $next->is(ClassNameResolver::NAMES)
                && self::isCookieClass($next, $namespace, $imports);
        }

        if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])) {
            return self::startsTheSetCookieHeader($token);
        }

        if (!$token->is(ClassNameResolver::NAMES)) {
            return false;
        }

        // Cookie::create(…), Cookie::fromString(…) : un appel, pas une
        // constante ni `::class`.
        if (null !== $next && $next->is(\T_DOUBLE_COLON)) {
            return self::isCall($tokens, $index + 2) && self::isCookieClass($token, $namespace, $imports);
        }

        if (null === $next || '(' !== $next->text || !$token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])) {
            return false;
        }

        $name = strtolower(ltrim($token->text, '\\'));
        $previous = $tokens[$index - 1] ?? null;
        if (null !== $previous && $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR])) {
            return \in_array($name, self::COOKIE_METHODS, true);
        }

        return (null === $previous || !$previous->is(self::NOT_A_FUNCTION_CALL))
            && \in_array($name, self::COOKIE_FUNCTIONS, true);
    }

    /**
     * @param array<string, string> $imports
     */
    private static function isCookieClass(PhpToken $name, string $namespace, array $imports): bool
    {
        return \in_array(ClassNameResolver::resolve($name, $namespace, $imports), self::COOKIE_CLASSES, true);
    }

    /**
     * Un nom de méthode en $index, suivi de `(`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isCall(array $tokens, int $index): bool
    {
        $method = $tokens[$index] ?? null;
        $open = $tokens[$index + 1] ?? null;

        return null !== $method && $method->is(\T_STRING) && null !== $open && '(' === $open->text;
    }

    /**
     * Une chaîne littérale, ou le premier morceau d'une chaîne interpolée, qui
     * commence par l'en-tête. Entre guillemets doubles (et dans une chaîne
     * interpolée), PHP décode `\x53` ou `\123` : la comparaison se fait sur le
     * texte décodé.
     */
    private static function startsTheSetCookieHeader(PhpToken $token): bool
    {
        $text = $token->text;
        if ($token->is(\T_CONSTANT_ENCAPSED_STRING)) {
            $text = str_starts_with($text, '"') ? stripcslashes(substr($text, 1, -1)) : substr($text, 1, -1);
        } else {
            $text = stripcslashes($text);
        }

        return str_starts_with(strtr(strtolower(ltrim($text)), '_', '-'), self::SET_COOKIE_HEADER);
    }
}
