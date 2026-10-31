<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * La forme d'un appel de membre, écrite une fois pour les recenseurs par jetons (`AuthorizationSites`,
 * `PathIsDecidedCanonicallyTest`, #748) : un nom précédé de `->`, `?->` ou `::` et suivi de `(`.
 *
 * Un recenseur qui réécrit cette forme à sa façon rate l'une des trois écritures sans que l'autre le voie : l'appel
 * nullsafe (`$request?->getPathInfo()`) et l'appel statique sont aussi des appels. Le nom lui-même (sa casse, son
 * espace de noms) reste le jugement du recenseur : PHP ne distingue pas la casse d'un nom de méthode.
 */
final class MemberCalls
{
    /** Ce qui précède le nom d'un membre appelé : l'objet, l'objet nullsafe ou la classe. */
    private const array MEMBER_ACCESS = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON];

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
        $before = $tokens[$index - 1] ?? null;
        $after = $tokens[$index + 1] ?? null;

        return $before instanceof PhpToken && $before->is(self::MEMBER_ACCESS)
            && $after instanceof PhpToken && '(' === $after->text;
    }
}
