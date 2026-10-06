<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recense les classes d'un répertoire qui implémentent ou étendent un type
 * donné, d'après ce que déclarent les sources — jamais d'après le nom des
 * fichiers (issue #348).
 *
 * Déduire la classe du chemin (PSR-4) ignorait sans bruit un fichier mal nommé
 * ou qui en déclare plusieurs : un garde-fou qui ne voit pas une classe la
 * laisse passer. Ici, chaque `class Nom` déclarée est lue par jetons
 * (PhpToken) ; si l'autoload ne la trouve pas, le fichier est chargé
 * directement, et une classe qui reste introuvable fait échouer le recensement
 * plutôt que de disparaître.
 *
 * Partagé par les garde-fous qui recensent les ProblemExceptionInterface de
 * src/ (ExceptionLogLevelCoverageTest, ProblemDetailStaysStaticTest), pour
 * qu'ils voient exactement le même périmètre.
 */
final class DeclaredClasses
{
    private const array IGNORED = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return list<class-string<T>> triées, pour un diagnostic stable
     */
    public static function implementing(string $directory, string $type): array
    {
        return array_values(array_filter(self::all($directory), static fn (string $class): bool => is_subclass_of($class, $type)));
    }

    /**
     * Toutes les classes nommées que déclarent les fichiers du répertoire.
     *
     * @return list<class-string> triées, pour un diagnostic stable
     */
    public static function all(string $directory): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                array_push($found, ...self::declaredIn($file->getPathname()));
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<class-string> classes déclarées dans le fichier, chargées
     */
    private static function declaredIn(string $path): array
    {
        $declared = self::classNames((string) file_get_contents($path));
        $loaded = [];
        foreach ($declared as $class) {
            if (!class_exists($class)) {
                // Hors PSR-4 : l'autoload ne sait pas où la trouver.
                require_once $path;
            }
            if (!class_exists($class)) {
                throw new \LogicException(\sprintf('%s déclare %s, introuvable même après chargement.', $path, $class));
            }
            $loaded[] = $class;
        }

        return $loaded;
    }

    /**
     * @return list<string> FQCN des classes nommées déclarées (ni anonymes, ni `X::class`)
     */
    private static function classNames(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->is(self::IGNORED),
        ));

        $namespace = '';
        $classes = [];
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_NAMESPACE)) {
                $namespace = $tokens[$index + 1]->text ?? '';
            } elseif ($token->is(\T_CLASS)
                && (!isset($tokens[$index - 1]) || !$tokens[$index - 1]->is([\T_NEW, \T_DOUBLE_COLON]))
                && isset($tokens[$index + 1]) && $tokens[$index + 1]->is(\T_STRING)) {
                $classes[] = ltrim($namespace.'\\'.$tokens[$index + 1]->text, '\\');
            }
        }

        return $classes;
    }
}
