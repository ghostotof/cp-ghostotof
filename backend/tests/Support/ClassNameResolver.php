<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Generator;
use PhpToken;

/**
 * La résolution d'un nom de classe écrit dans les sources, telle que PHP la
 * fait, pour les recenseurs par jetons qui doivent savoir quelle classe un nom
 * désigne ({@see BareExceptionInstantiations},
 * {@see \App\Tests\Shared\Infrastructure\Http\ProblemDetailStaysStaticTest}).
 * Écrite une fois (issue #507) : chacun avait la sienne, et celle du second ne
 * lisait ni les listes `use A, B;`, ni les noms `namespace\X`, et comparait
 * les noms en tenant compte de la casse.
 *
 * Tout est rendu en minuscules, sans `\` initial : PHP résout les classes sans
 * tenir compte de la casse, la comparaison se fait donc sur cette forme.
 *
 * Un import groupé (`use Foo\{A, B as C};`) est lu comme une liste d'imports,
 * bien que {@see UnsortedImports} le refuse dans `src/` et `tests/` : le
 * recenseur ne doit pas dépendre de ce refus pour juger un nom. `self`,
 * `static` et `parent` sont laissés à l'appelant : seul lui sait dans quelle
 * classe il se trouve.
 */
final class ClassNameResolver
{
    /** Les jetons d'un nom de classe écrit dans le code, que {@see self::resolve()} sait lire. */
    public const array NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE];

    /** Les jetons d'un nom de classe qu'un `use` importe. */
    private const array IMPORTED_NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];

    /** Le préfixe d'un nom relatif à l'espace de noms courant (T_NAME_RELATIVE), quelle que soit sa casse. */
    private const string RELATIVE_PREFIX = 'namespace\\';

    /**
     * L'espace de noms que déclare le `namespace` en $index, en minuscules ;
     * vide pour l'espace de noms global à accolades (`namespace { … }`). Les
     * imports d'un espace de noms ne valent pas dans le suivant : l'appelant
     * les remet à zéro ici.
     *
     * @param list<PhpToken> $tokens
     */
    public static function namespaceDeclaredAt(array $tokens, int $index): string
    {
        $name = $tokens[$index + 1] ?? null;

        return null !== $name && $name->is([\T_STRING, \T_NAME_QUALIFIED]) ? strtolower($name->text) : '';
    }

    /**
     * Les imports d'un `use A, B as C;` ou d'un `use A\{B, C as D};` dont le
     * premier nom est en $index : alias en minuscules => classe importée en
     * minuscules. Un `use function`, un `use const` (groupés ou non), ou le
     * `use (…)` d'une fonction anonyme, n'importe aucune classe. Un `use` de
     * trait n'est pas un import : l'appelant qui parcourt les `use` à la main ne
     * lit que ceux de l'espace de noms, comme le fait {@see self::scopes()}.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array<string, string>
     */
    public static function imports(array $tokens, int $index): array
    {
        $imports = [];
        while (null !== ($name = $tokens[$index] ?? null) && $name->is(self::IMPORTED_NAMES)) {
            if (self::opensGroup($tokens, $index + 1)) {
                // Un import groupé est une instruction à lui seul : aucune virgule ne le prolonge.
                return self::groupedImports($tokens, $index + 3, strtolower(ltrim($name->text, '\\')).'\\');
            }

            [$alias, $class, $index] = self::importedAt($tokens, $index, '');
            $imports[$alias] = $class;

            $separator = $tokens[$index] ?? null;
            if (null === $separator || ',' !== $separator->text) {
                break;
            }
            ++$index;
        }

        return $imports;
    }

    /**
     * Le nom en $index importé tel quel ou sous un alias (`B as C`), derrière le préfixe d'un import groupé.
     *
     * @param list<PhpToken> $tokens
     * @param string         $prefix `a\b\` pour le membre d'un `use A\B\{…}`, vide sinon
     *
     * @return array{string, string, int} l'alias, la classe importée, l'index du jeton qui suit
     */
    private static function importedAt(array $tokens, int $index, string $prefix): array
    {
        $class = $prefix.strtolower(ltrim($tokens[$index]->text, '\\'));
        $as = $tokens[$index + 1] ?? null;
        $explicitAlias = $tokens[$index + 2] ?? null;
        if (null !== $as && $as->is(\T_AS) && null !== $explicitAlias && $explicitAlias->is(\T_STRING)) {
            return [strtolower($explicitAlias->text), $class, $index + 3];
        }

        // Sans `as`, l'alias est le dernier segment du nom importé.
        $lastSeparator = strrpos($class, '\\');

        return [false === $lastSeparator ? $class : substr($class, $lastSeparator + 1), $class, $index + 1];
    }

    /**
     * Le jeton en $index est-il la barre oblique qui précède l'accolade d'un import groupé (`use A\{`) ?
     *
     * @param list<PhpToken> $tokens
     */
    private static function opensGroup(array $tokens, int $index): bool
    {
        $separator = $tokens[$index] ?? null;
        $brace = $tokens[$index + 1] ?? null;

        return null !== $separator && $separator->is(\T_NS_SEPARATOR) && null !== $brace && '{' === $brace->text;
    }

    /**
     * Les membres d'un import groupé, de $index (le jeton qui suit `{`) jusqu'à `}` : un membre `function` ou
     * `const` n'importe aucune classe et est passé en entier, alias compris.
     *
     * @param list<PhpToken> $tokens
     * @param string         $prefix le nom qui précède l'accolade, en minuscules, `\` final compris
     *
     * @return array<string, string>
     */
    private static function groupedImports(array $tokens, int $index, string $prefix): array
    {
        $imports = [];
        while (null !== ($token = $tokens[$index] ?? null) && '}' !== $token->text) {
            if ($token->is(self::IMPORTED_NAMES)) {
                [$alias, $class, $index] = self::importedAt($tokens, $index, $prefix);
                $imports[$alias] = $class;
            } elseif ($token->is([\T_FUNCTION, \T_CONST])) {
                while (null !== ($token = $tokens[$index] ?? null) && !\in_array($token->text, [',', '}'], true)) {
                    ++$index;
                }
            } else {
                ++$index;
            }
        }

        return $imports;
    }

    /**
     * Chaque jeton du code avec l'espace de noms et les imports qui valent à
     * cet endroit : la boucle que partagent les recenseurs de sites
     * ({@see BareExceptionInstantiations}, {@see CookieConstructions}). Les
     * jetons `namespace` et `use` eux-mêmes ne sont pas rendus ; les imports
     * repartent de zéro à chaque `namespace`, et à la fin d'un bloc
     * `namespace X { … }`. Seul un `use` de l'espace de noms importe : celui
     * d'un corps de classe est un trait, celui d'une fonction anonyme une
     * capture.
     *
     * @param list<PhpToken> $tokens
     *
     * @return Generator<int, array{string, array<string, string>}> index du jeton => [espace de noms, imports]
     */
    public static function scopes(array $tokens): Generator
    {
        $namespace = '';
        /** @var array<string, string> $imports alias en minuscules => classe importée en minuscules */
        $imports = [];
        $depth = 0;
        // La profondeur d'accolades du corps d'un `namespace X { … }` ; null pour un `namespace X;` ou aucun.
        $blockDepth = null;
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_NAMESPACE)) {
                $namespace = self::namespaceDeclaredAt($tokens, $index);
                $imports = [];
                $blockDepth = self::opensBlock($tokens, $index) ? $depth + 1 : null;
            } elseif ($token->is(\T_USE)) {
                if ($depth === ($blockDepth ?? 0)) {
                    $imports = array_merge($imports, self::imports($tokens, $index + 1));
                }
            } else {
                if (PhpSources::opensBrace($token)) {
                    ++$depth;
                } elseif ($token->is('}')) {
                    if ($depth === $blockDepth) {
                        $namespace = '';
                        $imports = [];
                        $blockDepth = null;
                    }
                    --$depth;
                }
                yield $index => [$namespace, $imports];
            }
        }
    }

    /**
     * Le `namespace` en $index ouvre-t-il un bloc (`namespace X {`, `namespace {`) plutôt qu'il ne finit par `;` ?
     *
     * @param list<PhpToken> $tokens
     */
    private static function opensBlock(array $tokens, int $index): bool
    {
        $name = $tokens[$index + 1] ?? null;
        $next = null !== $name && $name->is([\T_STRING, \T_NAME_QUALIFIED]) ? $tokens[$index + 2] ?? null : $name;

        return null !== $next && '{' === $next->text;
    }

    /**
     * Le nom écrit après `new` en $index - 1 : la classe instanciée, ou celle
     * qu'étend une classe anonyme (`new class(…) extends X {}`). Null pour une
     * expression (`new $class`) ou une classe anonyme sans parent.
     *
     * @param list<PhpToken> $tokens
     */
    public static function instantiatedName(array $tokens, int $index): ?PhpToken
    {
        $next = $tokens[$index] ?? null;
        if (null === $next || !$next->is(\T_CLASS)) {
            return null !== $next && $next->is(self::NAMES) ? $next : null;
        }

        // Classe anonyme : son parent se lit avant l'accolade du corps.
        for ($cursor = $index + 1; null !== ($token = $tokens[$cursor] ?? null) && '{' !== $token->text; ++$cursor) {
            if ($token->is(\T_EXTENDS)) {
                $parent = $tokens[$cursor + 1] ?? null;

                return null !== $parent && $parent->is(self::NAMES) ? $parent : null;
            }
        }

        return null;
    }

    /**
     * La classe que désigne ce nom, en minuscules sans `\` initial : un nom
     * complet tel quel, `namespace\X` dans l'espace de noms courant, un nom
     * dont le premier segment est un alias importé dans la cible de l'import,
     * tout autre nom relatif dans l'espace de noms courant — seul un fichier
     * sans espace de noms atteint ainsi les classes globales.
     *
     * @param string                $namespace l'espace de noms courant, en minuscules ({@see self::namespaceDeclaredAt()})
     * @param array<string, string> $imports   ceux du fichier ({@see self::imports()})
     */
    public static function resolve(PhpToken $name, string $namespace, array $imports): string
    {
        $text = strtolower($name->text);
        if ($name->is(\T_NAME_FULLY_QUALIFIED)) {
            return ltrim($text, '\\');
        }
        if ($name->is(\T_NAME_RELATIVE)) {
            return ltrim($namespace.'\\'.substr($text, \strlen(self::RELATIVE_PREFIX)), '\\');
        }

        $segments = explode('\\', $text, 2);
        if (isset($imports[$segments[0]])) {
            return $imports[$segments[0]].(isset($segments[1]) ? '\\'.$segments[1] : '');
        }

        return ltrim($namespace.'\\'.$text, '\\');
    }
}
