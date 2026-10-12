<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense les `new Exception`, `new LogicException`, `new RuntimeException`
 * et `new InvalidArgumentException` nus d'un répertoire (issues #338 et #383),
 * d'après les jetons des sources et non
 * d'après un grep : un `use LogicException;` suivi de `new LogicException`, un
 * alias, un import dans une liste à virgules ou une casse différente désignent
 * la même classe pour PHP, et un homonyme de l'espace de noms courant n'en est
 * pas une.
 *
 * Toute instanciation compte, levée ou non : une exception construite puis
 * levée plus loin reste une exception générique, et une classe anonyme qui
 * l'étend sans rien y ajouter aussi. Les autres classes de la SPL
 * ({@see \DomainException}…) sont hors périmètre : aucune n'est levée nue dans
 * `src/`, et les ajouter ici est la marche à suivre le jour où l'une le serait.
 *
 * Les noms se résolvent par {@see ClassNameResolver}, partagé avec
 * {@see \App\Tests\Shared\Infrastructure\Http\ProblemDetailStaysStaticTest}
 * (issue #507) : un nom relatif d'un fichier à espace de noms y désigne une
 * classe de cet espace, jamais une classe globale visée. Limite assumée : un
 * import groupé (`use Foo\{A, B};`) n'est pas résolu, voir sa documentation ;
 * aucune des classes visées n'a d'ailleurs d'espace de noms à grouper.
 */
final class BareExceptionInstantiations
{
    /** Noms en minuscules, sans `\` initial : PHP résout les classes sans tenir compte de la casse. */
    private const array FORBIDDEN = ['exception', 'logicexception', 'runtimeexception', 'invalidargumentexception'];

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
            } elseif ($token->is(\T_NEW)) {
                $class = self::instantiatedName($tokens, $index + 1);
                if (null !== $class && \in_array(ClassNameResolver::resolve($class, $namespace, $imports), self::FORBIDDEN, true)) {
                    $lines[] = $token->line;
                }
            }
        }

        return $lines;
    }

    /**
     * Le nom écrit après `new` : la classe instanciée, ou celle qu'étend une
     * classe anonyme (`new class(…) extends X {}`). Null pour une expression
     * (`new $class`) ou une classe anonyme sans parent.
     *
     * @param list<PhpToken> $tokens
     */
    private static function instantiatedName(array $tokens, int $index): ?PhpToken
    {
        $next = $tokens[$index] ?? null;
        if (null === $next || !$next->is(\T_CLASS)) {
            return null !== $next && $next->is(ClassNameResolver::NAMES) ? $next : null;
        }

        // Classe anonyme : son parent se lit avant l'accolade du corps.
        for ($cursor = $index + 1; null !== ($token = $tokens[$cursor] ?? null) && '{' !== $token->text; ++$cursor) {
            if ($token->is(\T_EXTENDS)) {
                $parent = $tokens[$cursor + 1] ?? null;

                return null !== $parent && $parent->is(ClassNameResolver::NAMES) ? $parent : null;
            }
        }

        return null;
    }
}
