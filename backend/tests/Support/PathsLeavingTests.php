<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense les chemins que `tests/` bâtit depuis `__DIR__` et qui en sortent
 * (issue #629). Deux formes, lues par jetons :
 * - `\dirname(__DIR__, n)`, qualifié ou non, `n` absent ou entier littéral ;
 * - `__DIR__` seul.
 * Chacune suivie ou non de `.` et d'un littéral (`'/../../src'`, entre
 * apostrophes ou guillemets). Le chemin est résolu lexicalement depuis le
 * répertoire du fichier, relatif au backend : `tests/Shared` et
 * `\dirname(__DIR__, 2).'/src'` donnent `src`, hors de `tests/` ;
 * `\dirname(__DIR__, 4).'/tests/bootstrap.php'` y redescend, et passe.
 *
 * Un niveau qui n'est pas un entier littéral est signalé : le chemin ne se
 * résout pas. Une méthode homonyme (`->dirname(`, `::dirname(`) n'est pas la
 * native, son `__DIR__` est lu seul.
 *
 * Non vus : un appel imbriqué sur le résultat (`\dirname(\dirname(__DIR__))`,
 * `realpath()`), un chemin repris d'une variable (`$root = __DIR__;` puis
 * `$root.'/../..'`), un second littéral concaténé après le premier,
 * `__FILE__`. Aucun n'est écrit dans `tests/` aujourd'hui.
 */
final class PathsLeavingTests
{
    private const string CONFINEMENT = 'tests';

    /**
     * @param string $tests le répertoire `tests/` du backend, absolu
     *
     * @return list<string> `chemin relatif au backend:ligne problème`, triés pour un diagnostic stable
     */
    public static function in(string $tests): array
    {
        $backend = \dirname($tests);
        $found = [];
        foreach (PhpSources::files($tests) as $path) {
            $relative = substr($path, \strlen($backend) + 1);
            foreach (self::inCode(RepositoryFiles::read($path), \dirname($relative)) as $problem) {
                $found[] = $relative.':'.$problem;
            }
        }

        return $found;
    }

    /**
     * @param string $directory le répertoire du fichier, relatif au backend (`tests/Shared`)
     *
     * @return list<string> `ligne problème` pour chaque chemin qui sort de `tests/`, dans l'ordre du code
     */
    public static function inCode(string $code, string $directory): array
    {
        $tokens = PhpSources::significantTokens($code);
        $problems = [];
        $index = 0;
        while ($index < \count($tokens)) {
            $token = $tokens[$index];
            if (self::isDirnameOfDir($tokens, $index)) {
                $levels = self::levels($tokens, $index + 3);
                if (null === $levels) {
                    $problems[] = $token->line.' \\dirname(__DIR__, …) : niveau non littéral';
                    $index += 3;

                    continue;
                }
                [$count, $next] = $levels;
            } elseif ($token->is(\T_DIR)) {
                [$count, $next] = [0, $index + 1];
            } else {
                ++$index;

                continue;
            }

            $resolved = self::resolve($directory, $count, self::appendedLiteral($tokens, $next));
            if (!self::staysInTests($resolved)) {
                $problems[] = $token->line.' '.('' === $resolved ? '.' : $resolved).' : hors de '.self::CONFINEMENT.'/';
            }
            $index = $next;
        }

        return $problems;
    }

    /**
     * `dirname(__DIR__` ou `\dirname(__DIR__`, la native, jamais une méthode
     * ni une déclaration.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isDirnameOfDir(array $tokens, int $index): bool
    {
        $token = $tokens[$index];
        $name = $token->is(\T_NAME_FULLY_QUALIFIED) ? substr($token->text, 1) : ($token->is(\T_STRING) ? $token->text : null);
        if (null === $name || 'dirname' !== strtolower($name)) {
            return false;
        }

        $previous = $tokens[$index - 1] ?? null;

        return '(' === ($tokens[$index + 1] ?? null)?->text
            && true === ($tokens[$index + 2] ?? null)?->is(\T_DIR)
            && (null === $previous || !$previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION]));
    }

    /**
     * Le nombre de niveaux de `dirname(__DIR__` suivi de `)` ou de `, n)`, et
     * l'index qui suit la parenthèse fermante ; `null` si `n` n'est pas un
     * entier littéral.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array{int, int}|null
     */
    private static function levels(array $tokens, int $index): ?array
    {
        if (')' === ($tokens[$index] ?? null)?->text) {
            return [1, $index + 1];
        }
        if (',' === ($tokens[$index] ?? null)?->text
            && true === ($tokens[$index + 1] ?? null)?->is(\T_LNUMBER)
            && ')' === ($tokens[$index + 2] ?? null)?->text) {
            return [(int) $tokens[$index + 1]->text, $index + 3];
        }

        return null;
    }

    /**
     * Le littéral concaténé juste après, sans ses délimiteurs, ou `''`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function appendedLiteral(array $tokens, int $index): string
    {
        if ('.' !== ($tokens[$index] ?? null)?->text || true !== ($tokens[$index + 1] ?? null)?->is(\T_CONSTANT_ENCAPSED_STRING)) {
            return '';
        }

        return substr($tokens[$index + 1]->text, 1, -1);
    }

    /**
     * Le chemin relatif au backend, `..` gardés en tête quand il en sort.
     */
    private static function resolve(string $directory, int $levels, string $literal): string
    {
        $segments = [];
        foreach (explode('/', $directory.str_repeat('/..', $levels).$literal) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment && [] !== $segments && '..' !== array_last($segments)) {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    private static function staysInTests(string $resolved): bool
    {
        return self::CONFINEMENT === $resolved || str_starts_with($resolved, self::CONFINEMENT.'/');
    }
}
