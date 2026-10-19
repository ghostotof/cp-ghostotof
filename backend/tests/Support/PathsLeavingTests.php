<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense les chemins que `tests/` bâtit depuis `__DIR__` et qui en sortent
 * (issue #629). Deux formes, lues par jetons :
 * - `\dirname(<chemin>[, n])`, qualifié ou non, où `<chemin>` est `__DIR__`
 *   suivi ou non de morceaux concaténés, et `n` un entier littéral en toute
 *   base, positionnel ou nommé (`levels:`), virgule finale admise ;
 * - `__DIR__` seul.
 * Chacune suivie ou non de morceaux concaténés : des littéraux entre
 * apostrophes ou guillemets, `DIRECTORY_SEPARATOR`, et le début d'une chaîne
 * interpolée, où la lecture s'arrête. Le chemin est résolu lexicalement
 * depuis le répertoire du fichier, relatif au backend : `tests/Shared` et
 * `\dirname(__DIR__, 2).'/src'` donnent `src`, hors de `tests/` ;
 * `\dirname(__DIR__, 4).'/tests/bootstrap.php'` y redescend, et passe.
 *
 * Un argument de `dirname` qui ne se résout pas (un niveau variable, un
 * chemin qui continue par une variable) est signalé : on ne sait pas où il
 * mène. Une méthode homonyme (`->dirname(`, `::dirname(`) n'est pas la
 * native, son `__DIR__` est lu seul. Après le premier morceau qui n'est pas
 * un littéral (une variable), le chemin est jugé sur ce qui précède.
 *
 * Non vus : un appel imbriqué sur le résultat (`\dirname(\dirname(__DIR__))`,
 * `realpath()`), un chemin repris d'une variable (`$root = __DIR__;` puis
 * `$root.'/../..'`), un chemin composé par `sprintf()` ou `implode()`, une
 * constante autre que `DIRECTORY_SEPARATOR`, et `__FILE__`.
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
     * @return list<string> `ligne problème` pour chaque chemin qui sort de `tests/` ou ne se résout pas, dans l'ordre du code
     */
    public static function inCode(string $code, string $directory): array
    {
        $tokens = PhpSources::significantTokens($code);
        $problems = [];
        for ($index = 0; $index < \count($tokens); ++$index) {
            $path = self::pathAt($tokens, $index, $directory);
            if (null === $path) {
                continue;
            }

            [$resolved, $next] = $path;
            $line = $tokens[$index]->line;
            if (null === $resolved) {
                $problems[] = $line.' \\dirname(__DIR__…) : non résolu';
            } elseif (!self::staysInTests($resolved)) {
                $problems[] = $line.' '.('' === $resolved ? '.' : $resolved).' : hors de '.self::CONFINEMENT.'/';
            }
            $index = $next - 1;
        }

        return $problems;
    }

    /**
     * Le chemin qui commence à cet index, résolu (`null` s'il ne se résout
     * pas), et l'index qui le suit ; `null` si aucun chemin n'y commence.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array{?string, int}|null
     */
    private static function pathAt(array $tokens, int $index, string $directory): ?array
    {
        if (self::isDirnameOfDir($tokens, $index)) {
            [$argument, $afterArgument, $complete] = self::concatenation($tokens, $index + 3, $directory);
            $levels = self::levels($tokens, $afterArgument);
            if (!$complete || null === $levels) {
                return [null, $index + 3];
            }
            [$count, $afterCall] = $levels;
            [$path, $next] = self::concatenation($tokens, $afterCall, self::normalized($argument.str_repeat('/..', $count)));

            return [self::normalized($path), $next];
        }

        if ($tokens[$index]->is(\T_DIR)) {
            [$path, $next] = self::concatenation($tokens, $index + 1, $directory);

            return [self::normalized($path), $next];
        }

        return null;
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
        $previous = $tokens[$index - 1] ?? null;

        return self::isGlobalName($token, 'dirname')
            && '(' === ($tokens[$index + 1] ?? null)?->text
            && true === ($tokens[$index + 2] ?? null)?->is(\T_DIR)
            && (null === $previous || !$previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION]));
    }

    /**
     * Les morceaux concaténés à partir de cet index (chacun précédé de `.`),
     * ajoutés au chemin de base : le chemin, l'index qui suit le dernier
     * morceau lu, et si la lecture s'est arrêtée sur autre chose qu'un `.`
     * (vrai : la concaténation est finie ; faux : un morceau inconnu suit).
     *
     * @param list<PhpToken> $tokens
     *
     * @return array{string, int, bool}
     */
    private static function concatenation(array $tokens, int $index, string $path): array
    {
        while ('.' === ($tokens[$index] ?? null)?->text) {
            $piece = $tokens[$index + 1] ?? null;
            if (true === $piece?->is(\T_CONSTANT_ENCAPSED_STRING)) {
                $path .= substr($piece->text, 1, -1);
            } elseif (null !== $piece && self::isGlobalName($piece, 'DIRECTORY_SEPARATOR')) {
                $path .= '/';
            } else {
                // Une chaîne interpolée compte jusqu'à sa première variable.
                $start = $tokens[$index + 2] ?? null;
                if ('"' === $piece?->text && true === $start?->is(\T_ENCAPSED_AND_WHITESPACE)) {
                    $path .= $start->text;
                }

                return [$path, $index, false];
            }
            $index += 2;
        }

        return [$path, $index, true];
    }

    /**
     * Le niveau de `dirname(…` à partir de la parenthèse ou de la virgule
     * qui suit le chemin, et l'index qui suit la parenthèse fermante ;
     * `null` si le niveau n'est pas un entier littéral.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array{int, int}|null
     */
    private static function levels(array $tokens, int $index): ?array
    {
        $at = static fn (int $offset): ?PhpToken => $tokens[$index + $offset] ?? null;
        if (')' === $at(0)?->text) {
            return [1, $index + 1];
        }
        if (',' !== $at(0)?->text) {
            return null;
        }

        $offset = 1;
        if (')' === $at(1)?->text) {
            return [1, $index + 2];
        }
        if (true === $at(1)?->is(\T_STRING) && 'levels' === $at(1)->text && ':' === $at(2)?->text) {
            $offset = 3;
        }
        $number = $at($offset);
        if (true !== $number?->is(\T_LNUMBER)) {
            return null;
        }
        if (',' === $at($offset + 1)?->text) {
            ++$offset;
        }

        return ')' === $at($offset + 1)?->text ? [self::integer($number->text), $index + $offset + 2] : null;
    }

    /**
     * La valeur d'un entier littéral de PHP, en toute base, séparateurs `_`
     * compris : `(int) '0x3'` vaudrait 0.
     */
    private static function integer(string $literal): int
    {
        $digits = strtolower(str_replace('_', '', $literal));

        return (int) match (true) {
            str_starts_with($digits, '0x') => hexdec(substr($digits, 2)),
            str_starts_with($digits, '0b') => bindec(substr($digits, 2)),
            str_starts_with($digits, '0o') => octdec(substr($digits, 2)),
            1 < \strlen($digits) && str_starts_with($digits, '0') => octdec(substr($digits, 1)),
            default => $digits,
        };
    }

    /**
     * Un nom global d'un seul segment, qualifié ou non, sans tenir compte de
     * la casse.
     */
    private static function isGlobalName(PhpToken $token, string $name): bool
    {
        $text = match (true) {
            $token->is(\T_NAME_FULLY_QUALIFIED) => substr($token->text, 1),
            $token->is(\T_STRING) => $token->text,
            default => null,
        };

        return null !== $text && 0 === strcasecmp($text, $name);
    }

    /**
     * Le chemin relatif au backend, `..` gardés en tête quand il en sort.
     */
    private static function normalized(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
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
