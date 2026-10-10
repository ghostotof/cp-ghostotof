<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recense les `new \Exception`, `new \LogicException`, `new \RuntimeException`
 * et `new \InvalidArgumentException` nus d'un répertoire (issues #338 et #383),
 * d'après les jetons des sources et non
 * d'après un grep : un `use LogicException;` suivi de `new LogicException`, un
 * alias, un import dans une liste à virgules ou une casse différente désignent
 * la même classe pour PHP, et un homonyme de l'espace de noms courant n'en est
 * pas une.
 *
 * Toute instanciation compte, levée ou non : une exception construite puis
 * levée plus loin reste une exception générique, et une classe anonyme qui
 * l'étend sans rien y ajouter aussi. Les autres classes de la SPL
 * (\DomainException…) sont hors périmètre : aucune n'est levée nue dans
 * `src/`, et les ajouter ici est la marche à suivre le jour où l'une le serait.
 *
 * Limite assumée : un import groupé (`use Foo\{A, B};`) n'est pas résolu. Le
 * projet n'en écrit pas, et aucune des classes visées n'a d'espace de noms à
 * grouper.
 */
final class BareExceptionInstantiations
{
    /** Noms en minuscules, sans `\` initial : PHP résout les classes sans tenir compte de la casse. */
    private const array FORBIDDEN = ['exception', 'logicexception', 'runtimeexception', 'invalidargumentexception'];

    private const array NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];

    /**
     * @return list<string> `chemin:ligne` de chaque instanciation, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        $found = [];
        foreach (PhpSources::files($directory) as $path) {
            foreach (self::inCode((string) file_get_contents($path)) as $line) {
                $found[] = $path.':'.$line;
            }
        }

        return $found;
    }

    /**
     * @return list<int> lignes des instanciations interdites, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $namespaced = false;
        /** @var array<string, string> $imports alias en minuscules => classe importée en minuscules */
        $imports = [];
        $lines = [];
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_NAMESPACE)) {
                $namespaced = true;
            } elseif ($token->is(\T_USE)) {
                $imports = array_merge($imports, self::imports($tokens, $index + 1));
            } elseif ($token->is(\T_NEW)) {
                $class = self::instantiatedName($tokens, $index + 1);
                if (null !== $class && \in_array(self::resolve($class, $namespaced, $imports), self::FORBIDDEN, true)) {
                    $lines[] = $token->line;
                }
            }
        }

        return $lines;
    }

    /**
     * Les imports d'un `use A, B as C;`. Un `use function`/`use const`, ou le
     * `use (…)` d'une fonction anonyme, n'importe aucune classe ; un `use` de
     * trait donne au pire un alias qui ne désigne aucune classe visée.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array<string, string>
     */
    private static function imports(array $tokens, int $index): array
    {
        $imports = [];
        while (null !== ($name = $tokens[$index] ?? null) && $name->is(self::NAMES)) {
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
     * Le nom écrit après `new` : la classe instanciée, ou celle qu'étend une
     * classe anonyme (`new class(…) extends X {}`). Null pour une expression
     * (`new $class`) ou une classe anonyme sans parent.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function instantiatedName(array $tokens, int $index): ?\PhpToken
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
     * La classe que PHP instancierait, en minuscules sans `\` initial ; null
     * pour un nom relatif à l'espace de noms courant.
     *
     * @param array<string, string> $imports
     */
    private static function resolve(\PhpToken $name, bool $namespaced, array $imports): ?string
    {
        if ($name->is(\T_NAME_FULLY_QUALIFIED)) {
            return strtolower(ltrim($name->text, '\\'));
        }

        $segments = explode('\\', strtolower($name->text), 2);
        if (isset($imports[$segments[0]])) {
            return $imports[$segments[0]].(isset($segments[1]) ? '\\'.$segments[1] : '');
        }

        // Sans import, un nom relatif se résout dans l'espace de noms courant :
        // seul un fichier sans espace de noms atteint les classes globales.
        return $namespaced ? null : strtolower($name->text);
    }
}
