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
 * - `new Cookie(…)`, ou une classe anonyme qui l'étend, et tout appel
 *   statique de la classe (`Cookie::create()`, `Cookie::fromString()`) — la
 *   classe de HttpFoundation, résolue comme PHP la résout
 *   ({@see ClassNameResolver} : import, alias, casse, homonyme de l'espace de
 *   noms courant) ;
 * - `->clearCookie(…)`, qui construit le cookie d'expiration avec ses propres
 *   attributs ;
 * - `->withSecure()`, `->withHttpOnly()`, `->withSameSite()`,
 *   `->withDomain()`, `->withPath()`, `->withPartitioned()`, `->withRaw()` :
 *   la retouche d'un cookie déjà construit, celui de la fabrique compris ;
 * - les fonctions natives `setcookie()` et `setrawcookie()` ;
 * - une chaîne qui commence par `Set-Cookie`, quelle que soit sa casse :
 *   l'en-tête écrit à la main (`header()`, `$headers->set()`).
 *
 * Non vus, limite assumée : un appel dynamique (`$cookie->$method()`,
 * `call_user_func()`), une méthode `with…()` homonyme d'une autre classe
 * (vue à tort : renommer), l'en-tête composé par concaténation
 * (`'Set-'.'Cookie'`), un import groupé (cf. {@see ClassNameResolver}), et ce
 * que fait le code du vendor — le cookie du login de Lexik, que
 * {@see \App\Tests\Security\Authentication\AuthCookieAttributesTest} lit sur
 * la réponse.
 */
final class CookieConstructions
{
    /** La classe de cookie de HttpFoundation, en minuscules sans `\` initial. */
    private const string COOKIE_CLASS = 'symfony\\component\\httpfoundation\\cookie';

    /** Méthodes d'instance qui construisent ou retouchent un cookie, en minuscules. */
    private const array COOKIE_METHODS = [
        'clearcookie',
        'withsecure',
        'withhttponly',
        'withsamesite',
        'withdomain',
        'withpath',
        'withpartitioned',
        'withraw',
    ];

    /** Fonctions natives qui posent un cookie, en minuscules. */
    private const array COOKIE_FUNCTIONS = ['setcookie', 'setrawcookie'];

    /** Ce qui, avant un nom suivi de `(`, en fait autre chose qu'un appel de fonction. */
    private const array NOT_A_FUNCTION_CALL = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW, \T_CONST];

    private const string SET_COOKIE_HEADER = 'set-cookie';

    /**
     * @return list<string> `chemin:ligne` de chaque site, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        $found = [];
        foreach (PhpSources::files($directory) as $path) {
            foreach (self::inCode(RepositoryFiles::read($path)) as $line) {
                $found[] = $path.':'.$line;
            }
        }

        return $found;
    }

    /**
     * @return list<int> lignes des sites, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $namespace = '';
        /** @var array<string, string> $imports alias en minuscules => classe importée en minuscules */
        $imports = [];
        $lines = [];
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_NAMESPACE)) {
                $namespace = ClassNameResolver::namespaceDeclaredAt($tokens, $index);
                $imports = [];
            } elseif ($token->is(\T_USE)) {
                $imports = array_merge($imports, ClassNameResolver::imports($tokens, $index + 1));
            } elseif (self::buildsACookie($tokens, $index, $namespace, $imports)) {
                $lines[] = $token->line;
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

        if ($token->is(\T_NEW)) {
            $class = BareExceptionInstantiations::instantiatedName($tokens, $index + 1);

            return null !== $class && self::COOKIE_CLASS === ClassNameResolver::resolve($class, $namespace, $imports);
        }

        if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])) {
            return self::startsTheSetCookieHeader($token);
        }

        if (!$token->is(ClassNameResolver::NAMES)) {
            return false;
        }

        $next = $tokens[$index + 1] ?? null;
        $previous = $tokens[$index - 1] ?? null;
        $name = strtolower(ltrim($token->text, '\\'));

        // Cookie::create(…), Cookie::fromString(…) : un appel, pas une
        // constante ni `::class`.
        if (null !== $next && $next->is(\T_DOUBLE_COLON)) {
            return self::isCall($tokens, $index + 2)
                && self::COOKIE_CLASS === ClassNameResolver::resolve($token, $namespace, $imports);
        }

        if (null === $next || '(' !== $next->text || !$token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])) {
            return false;
        }

        if (null !== $previous && $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR])) {
            return \in_array($name, self::COOKIE_METHODS, true);
        }

        return (null === $previous || !$previous->is(self::NOT_A_FUNCTION_CALL))
            && \in_array($name, self::COOKIE_FUNCTIONS, true);
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
     * Une chaîne littérale entre guillemets simples ou doubles, ou le premier
     * morceau d'une chaîne interpolée, qui commence par l'en-tête.
     */
    private static function startsTheSetCookieHeader(PhpToken $token): bool
    {
        $text = $token->is(\T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : $token->text;

        return str_starts_with(strtolower(ltrim($text)), self::SET_COOKIE_HEADER);
    }
}
