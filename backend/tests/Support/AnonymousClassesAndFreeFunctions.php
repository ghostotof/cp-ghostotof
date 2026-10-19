<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense les classes anonymes et les fonctions hors classe d'un répertoire
 * (issue #432) : ce que {@see \App\Tests\Support\DeclaredClasses} ne voit pas, et donc ce
 * qu'aucune garde de signatures ne lit.
 *
 * Une fonction est hors classe quand l'accolade qui l'entoure n'est pas le
 * corps d'une classe, d'une interface, d'un trait ou d'un enum : au niveau du
 * fichier, mais aussi dans un bloc, le corps d'une méthode ou d'une closure,
 * où PHP la déclare dans l'espace global à l'exécution. Une closure
 * (`function (`), une fonction fléchée ou une fonction importée (`use
 * function`, import groupé compris) n'en est pas une.
 *
 * Lu par jetons et non par un grep : `new readonly class`, `new #[Attr]
 * class` et `Foo::class` ne se distinguent qu'au jeton qui suit `class`, et
 * une méthode d'une fonction déclarée dans une méthode qu'à l'accolade qui
 * l'entoure. Le même parcours sert à
 * {@see \App\Tests\Support\MisqualifiedNativeCalls}, qui
 * exempte les homonymes de fonctions natives déclarés par le fichier : une
 * seule définition de « hors classe ».
 */
final class AnonymousClassesAndFreeFunctions
{
    /**
     * @return list<string> `chemin:ligne: nature` de chaque déclaration, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        $found = [];
        foreach (PhpSources::files($directory) as $path) {
            foreach (self::inCode(RepositoryFiles::read($path)) as $entry) {
                $found[] = $path.':'.$entry;
            }
        }

        return $found;
    }

    /**
     * @return list<string> `ligne: nature` de chaque déclaration, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        return array_map(
            static fn (array $found): string => $found[0].': '.(null === $found[1] ? 'classe anonyme' : 'fonction '.$found[1].'()'),
            self::scan(PhpSources::significantTokens($code)),
        );
    }

    /**
     * @param list<PhpToken> $tokens jetons significatifs ({@see PhpSources::significantTokens()})
     *
     * @return list<string> noms des fonctions hors classe, tels qu'écrits, dans l'ordre du code
     */
    public static function freeFunctionNames(array $tokens): array
    {
        return array_values(array_filter(array_column(self::scan($tokens), 1), is_string(...)));
    }

    /**
     * @param list<PhpToken> $tokens
     *
     * @return list<array{int, ?string}> ligne, puis nom de la fonction hors classe, ou null pour une classe anonyme
     */
    private static function scan(array $tokens): array
    {
        $found = [];
        /** @var list<bool> $braces pour chaque accolade ouverte : est-ce le corps d'une déclaration de classe ? */
        $braces = [];
        $parentheses = 0;
        // Profondeurs de parenthèses auxquelles s'ouvriront les corps attendus,
        // en pile : une closure passée à `new class(…)` ouvre son accolade plus
        // profond, et peut déclarer elle-même une classe avant que le corps
        // externe ne s'ouvre.
        /** @var list<int> $pendingBodies */
        $pendingBodies = [];
        // Dans une instruction `use …;` (import, éventuellement groupé), un
        // `function` importe une fonction, il n'en déclare aucune.
        $inUseStatement = false;
        foreach ($tokens as $index => $token) {
            if ($token->is(PhpSources::CLASS_LIKE) && !self::declaresNothing($tokens, $index)) {
                $pendingBodies[] = $parentheses;
                if ($token->is(\T_CLASS) && !self::isNamed($tokens, $index)) {
                    $found[] = [$token->line, null];
                }
            } elseif ($token->is(\T_FUNCTION) && !self::declaresNothing($tokens, $index)) {
                $name = $inUseStatement ? null : self::declaredFunctionName($tokens, $index);
                // Au niveau du fichier, la pile est vide et end() rend false : hors classe aussi.
                if (null !== $name && !end($braces)) {
                    $found[] = [$token->line, $name];
                }
            } elseif ($token->is(\T_USE)) {
                // `use (…)` d'une closure : rien n'est importé.
                $inUseStatement = '(' !== ($tokens[$index + 1] ?? null)?->text;
            } elseif (';' === $token->text) {
                $inUseStatement = false;
            } elseif (PhpSources::opensBrace($token)) {
                // Hors d'un import groupé (`Foo\{`), l'accolade ouvre l'adaptation
                // d'un trait (`use T { … }`), qui se ferme sans `;` : l'import est fini.
                if ($inUseStatement && true !== ($tokens[$index - 1] ?? null)?->is(\T_NS_SEPARATOR)) {
                    $inUseStatement = false;
                }
                $opensBody = end($pendingBodies) === $parentheses;
                $braces[] = $opensBody;
                if ($opensBody) {
                    array_pop($pendingBodies);
                }
            } elseif ('}' === $token->text) {
                array_pop($braces);
            } elseif ('(' === $token->text) {
                ++$parentheses;
            } elseif (')' === $token->text) {
                --$parentheses;
            }
        }

        return $found;
    }

    /**
     * Un mot-clé qui ne déclare rien : `Foo::class` ou `$o::class` lit un nom,
     * et un argument nommé (`f(class: 1)`, `f(function: 2)`) n'est qu'une
     * étiquette.
     *
     * @param list<PhpToken> $tokens
     */
    private static function declaresNothing(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        return (null !== $previous && $previous->is(\T_DOUBLE_COLON)) || ':' === $next?->text;
    }

    /**
     * Une classe nommée porte son nom juste après `class` ; une classe anonyme
     * enchaîne sur `(`, `{`, `extends` ou `implements`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isNamed(array $tokens, int $index): bool
    {
        $next = $tokens[$index + 1] ?? null;

        return null !== $next && $next->is(\T_STRING);
    }

    /**
     * Le nom d'une fonction déclarée (`function nom(`, `function &nom(`), ou
     * null pour une closure. Tout jeton autre que `(` est un nom : un mot-clé
     * permis comme nom de fonction (`readonly`) n'est pas un T_STRING.
     *
     * @param list<PhpToken> $tokens
     */
    private static function declaredFunctionName(array $tokens, int $index): ?string
    {
        $next = $tokens[$index + 1] ?? null;
        if (null !== $next && '&' === $next->text) {
            $next = $tokens[$index + 2] ?? null;
        }

        return null !== $next && '(' !== $next->text ? $next->text : null;
    }
}
