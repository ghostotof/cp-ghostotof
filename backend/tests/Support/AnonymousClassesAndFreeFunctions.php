<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense les classes anonymes et les fonctions hors classe d'un répertoire
 * (issue #432) : ce que {@see DeclaredClasses} ne voit pas, et donc ce
 * qu'aucune garde de signatures ne lit.
 *
 * Une fonction est hors classe quand l'accolade qui l'entoure n'est pas le
 * corps d'une classe, d'une interface, d'un trait ou d'un enum : au niveau du
 * fichier, mais aussi dans le corps d'une méthode ou d'une closure, où PHP la
 * déclare dans l'espace global à l'exécution. Une closure (`function (`), une
 * fonction fléchée ou un `use function` n'en est pas une.
 *
 * Lu par jetons et non par un grep : `new readonly class`, `new #[Attr]
 * class` et `Foo::class` ne se distinguent qu'au jeton qui suit `class`, et
 * une méthode d'une fonction déclarée dans une méthode qu'à l'accolade qui
 * l'entoure.
 */
final class AnonymousClassesAndFreeFunctions
{
    /** Les jetons qui ouvrent une déclaration dont l'accolade suivante est le corps. */
    private const array CLASS_LIKE = [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM];

    /**
     * @return list<string> `chemin:ligne: nature` de chaque déclaration, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        $found = [];
        foreach (PhpSources::files($directory) as $path) {
            foreach (self::inCode((string) file_get_contents($path)) as $entry) {
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
        $tokens = PhpSources::significantTokens($code);

        $found = [];
        /** @var list<bool> $braces pour chaque accolade ouverte : est-ce le corps d'une déclaration de classe ? */
        $braces = [];
        $parentheses = 0;
        // Profondeur de parenthèses à laquelle s'ouvrira le corps attendu : une
        // closure passée à `new class(…)` ouvre son accolade plus profond.
        $pendingBodyAt = null;
        foreach ($tokens as $index => $token) {
            if ($token->is(self::CLASS_LIKE) && !self::readsAClassName($tokens, $index)) {
                $pendingBodyAt = $parentheses;
                if ($token->is(\T_CLASS) && !self::isNamed($tokens, $index)) {
                    $found[] = $token->line.': classe anonyme';
                }
            } elseif ($token->is(\T_FUNCTION)) {
                $name = self::declaredFunctionName($tokens, $index);
                // Au niveau du fichier, la pile est vide et end() rend false : hors classe aussi.
                if (null !== $name && !end($braces)) {
                    $found[] = $token->line.': fonction '.$name.'()';
                }
            } elseif (PhpSources::opensBrace($token)) {
                $braces[] = $pendingBodyAt === $parentheses;
                if ($pendingBodyAt === $parentheses) {
                    $pendingBodyAt = null;
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
     * `Foo::class` ou `$o::class` : `class` lit un nom, il n'ouvre rien.
     *
     * @param list<PhpToken> $tokens
     */
    private static function readsAClassName(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        return null !== $previous && $previous->is(\T_DOUBLE_COLON);
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
     * null pour une closure et pour un `use function`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function declaredFunctionName(array $tokens, int $index): ?string
    {
        $previous = $tokens[$index - 1] ?? null;
        if (null !== $previous && $previous->is(\T_USE)) {
            return null;
        }

        $next = $tokens[$index + 1] ?? null;
        if (null !== $next && '&' === $next->text) {
            $next = $tokens[$index + 2] ?? null;
        }

        return null !== $next && $next->is(\T_STRING) ? $next->text : null;
    }
}
