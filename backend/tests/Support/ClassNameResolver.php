<?php

declare(strict_types=1);

namespace App\Tests\Support;

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
 * Limite assumée : un import groupé (`use Foo\{A, B};`) n'est pas résolu —
 * {@see UnsortedImports} le refuse dans `src/` et `tests/`, et Rector n'en
 * produit jamais. `self`, `static` et `parent` sont laissés à l'appelant : seul
 * lui sait dans quelle classe il se trouve.
 */
final class ClassNameResolver
{
    /** Les jetons d'un nom de classe qu'un `use` importe. */
    private const array IMPORTED_NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];

    /** Le préfixe d'un nom relatif à l'espace de noms courant (T_NAME_RELATIVE), quelle que soit sa casse. */
    private const string RELATIVE_PREFIX = 'namespace\\';

    /**
     * L'espace de noms que déclare le `namespace` en $index, en minuscules ;
     * vide pour l'espace de noms global à accolades (`namespace { … }`).
     *
     * @param list<PhpToken> $tokens
     */
    public static function namespaceDeclaredAt(array $tokens, int $index): string
    {
        $name = $tokens[$index + 1] ?? null;

        return null !== $name && $name->is([\T_STRING, \T_NAME_QUALIFIED]) ? strtolower($name->text) : '';
    }

    /**
     * Les imports d'un `use A, B as C;` dont le premier nom est en $index :
     * alias en minuscules => classe importée en minuscules. Un `use function`,
     * un `use const`, ou le `use (…)` d'une fonction anonyme, n'importe aucune
     * classe ; un `use` de trait donne au pire un alias qui ne désigne aucune
     * classe — à l'appelant de ne lire que les `use` de premier niveau s'il
     * veut s'en garder.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array<string, string>
     */
    public static function imports(array $tokens, int $index): array
    {
        $imports = [];
        while (null !== ($name = $tokens[$index] ?? null) && $name->is(self::IMPORTED_NAMES)) {
            $class = strtolower(ltrim($name->text, '\\'));
            $as = $tokens[$index + 1] ?? null;
            $explicitAlias = $tokens[$index + 2] ?? null;
            if (null !== $as && $as->is(\T_AS) && null !== $explicitAlias && $explicitAlias->is(\T_STRING)) {
                $imports[strtolower($explicitAlias->text)] = $class;
                $index += 3;
            } else {
                // Sans `as`, l'alias est le dernier segment du nom importé.
                $lastSeparator = strrpos($class, '\\');
                $imports[false === $lastSeparator ? $class : substr($class, $lastSeparator + 1)] = $class;
                ++$index;
            }

            $separator = $tokens[$index] ?? null;
            if (null === $separator || ',' !== $separator->text) {
                break;
            }
            ++$index;
        }

        return $imports;
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
