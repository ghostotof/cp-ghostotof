<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * La forme d'un appel de membre, écrite une fois pour les recenseurs par jetons (`AuthorizationSites`,
 * `PathIsDecidedCanonicallyTest`, `CookieConstructions`, `PlainPasswordInputs`, #748) : un nom précédé de `->`, `?->`
 * ou `::` et suivi de `(`.
 *
 * Un recenseur qui réécrit cette forme à sa façon rate l'une des trois écritures sans que l'autre le voie : l'appel
 * nullsafe (`$request?->getPathInfo()`) et l'appel statique sont aussi des appels. Le nom lui-même (sa casse, son
 * espace de noms) reste le jugement du recenseur : PHP ne distingue pas la casse d'un nom de méthode. Trois
 * prédicats, pour trois besoins : tout appel de membre, l'appel d'un objet seul (une méthode d'instance
 * homonyme d'une méthode statique ne se confond pas avec elle), l'appel statique seul (`Classe::nom(`).
 */
final class MemberCalls
{
    /** Ce qui précède le nom d'une méthode d'instance appelée : l'objet ou l'objet nullsafe. */
    private const array INSTANCE_ACCESS = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR];

    /** Ce qui précède le nom d'une méthode statique appelée : la classe. */
    private const array STATIC_ACCESS = [\T_DOUBLE_COLON];

    private function __construct()
    {
    }

    /**
     * Le jeton à `$index` est-il le nom d'un membre que l'on appelle (`->nom(`, `?->nom(`, `::nom(`) ? Les appelables
     * de première classe (`->nom(...)`) sont des appels : la parenthèse y suit le nom aussi.
     *
     * @param list<PhpToken> $tokens les jetons significatifs du code (`PhpSources::significantTokens()`)
     */
    public static function isCallAt(array $tokens, int $index): bool
    {
        return self::isInstanceCallAt($tokens, $index) || self::isStaticCallAt($tokens, $index);
    }

    /**
     * Le jeton à `$index` est-il le nom d'une méthode d'un objet que l'on appelle (`->nom(`, `?->nom(`) ?
     *
     * @param list<PhpToken> $tokens
     */
    public static function isInstanceCallAt(array $tokens, int $index): bool
    {
        return self::isCalledAfter($tokens, $index, self::INSTANCE_ACCESS);
    }

    /**
     * Le jeton à `$index` est-il le nom d'une méthode statique que l'on appelle (`Classe::nom(`) ?
     *
     * @param list<PhpToken> $tokens
     */
    public static function isStaticCallAt(array $tokens, int $index): bool
    {
        return self::isCalledAfter($tokens, $index, self::STATIC_ACCESS);
    }

    /**
     * Un nom (`T_STRING` : `->$nom(` et `::$nom(` sont dynamiques, aucun nom à juger), précédé d'un de ces opérateurs
     * et suivi de `(`.
     *
     * @param list<PhpToken> $tokens
     * @param list<int>      $operators
     */
    private static function isCalledAfter(array $tokens, int $index, array $operators): bool
    {
        $name = $tokens[$index] ?? null;
        $before = $tokens[$index - 1] ?? null;
        $after = $tokens[$index + 1] ?? null;

        return $name instanceof PhpToken && $name->is(\T_STRING)
            && $before instanceof PhpToken && $before->is($operators)
            && $after instanceof PhpToken && '(' === $after->text;
    }
}
