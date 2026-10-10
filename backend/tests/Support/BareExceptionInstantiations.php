<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recense les `new \LogicException` et `new \RuntimeException` nus d'un
 * répertoire (issue #338), d'après les jetons des sources et non d'après un
 * grep : un `use LogicException;` suivi de `new LogicException`, un alias ou
 * une casse différente désignent la même classe pour PHP, et un homonyme de
 * l'espace de noms courant n'en est pas une.
 *
 * Toute instanciation compte, levée ou non : une exception construite puis
 * levée plus loin reste une exception générique. Une sous-classe de la SPL
 * (\InvalidArgumentException…) est hors périmètre.
 *
 * Limite assumée : un import groupé (`use Foo\{A, B};`) n'est pas résolu. Le
 * projet n'en écrit pas, et aucune des deux classes visées n'a d'espace de
 * noms à grouper.
 */
final class BareExceptionInstantiations
{
    /** Noms en minuscules, sans `\` initial : PHP résout les classes sans tenir compte de la casse. */
    private const array FORBIDDEN = ['logicexception', 'runtimeexception'];

    private const array IGNORED = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

    /**
     * @return list<string> `chemin:ligne` de chaque instanciation, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                foreach (self::inCode((string) file_get_contents($file->getPathname())) as $line) {
                    $found[] = $file->getPathname().':'.$line;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<int> lignes des instanciations interdites, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->is(self::IGNORED),
        ));

        $namespaced = false;
        /** @var array<string, string> $imports alias en minuscules => classe importée en minuscules */
        $imports = [];
        $lines = [];
        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;
            if (null === $next) {
                break;
            }

            if ($token->is(\T_NAMESPACE)) {
                $namespaced = true;
            } elseif ($token->is(\T_USE)) {
                $imports += self::import($tokens, $index + 1);
            } elseif ($token->is(\T_NEW) && \in_array(self::resolve($next, $namespaced, $imports), self::FORBIDDEN, true)) {
                $lines[] = $token->line;
            }
        }

        return $lines;
    }

    /**
     * Un `use Nom;` ou `use Nom as Alias;`. Un `use` de trait ou de fonction
     * anonyme donne au pire un alias qui ne désigne aucune classe visée.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array<string, string>
     */
    private static function import(array $tokens, int $index): array
    {
        $name = $tokens[$index] ?? null;
        if (null === $name || !$name->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
            return [];
        }

        $class = strtolower(ltrim($name->text, '\\'));
        $as = $tokens[$index + 1] ?? null;
        $explicitAlias = $tokens[$index + 2] ?? null;
        if (null !== $as && $as->is(\T_AS) && null !== $explicitAlias && $explicitAlias->is(\T_STRING)) {
            return [strtolower($explicitAlias->text) => $class];
        }

        // Sans `as`, l'alias est le dernier segment du nom importé.
        $lastSeparator = strrpos($class, '\\');

        return [false === $lastSeparator ? $class : substr($class, $lastSeparator + 1) => $class];
    }

    /**
     * La classe que PHP instancierait, en minuscules sans `\` initial ; null
     * si ce qui suit `new` n'est pas un nom (classe anonyme, expression).
     *
     * @param array<string, string> $imports
     */
    private static function resolve(\PhpToken $name, bool $namespaced, array $imports): ?string
    {
        if ($name->is(\T_NAME_FULLY_QUALIFIED)) {
            return strtolower(ltrim($name->text, '\\'));
        }

        if (!$name->is([\T_STRING, \T_NAME_QUALIFIED])) {
            return null;
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
