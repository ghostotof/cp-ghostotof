<?php

declare(strict_types=1);

namespace App\Tests\Support;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ce que les recenseurs par jetons ont en commun (DeclaredClasses,
 * BareExceptionInstantiations, CommentedClassNames, UnsortedImports,
 * MisqualifiedNativeCalls, AnonymousClassesAndFreeFunctions) : quels fichiers
 * ils lisent, quels jetons ils ignorent, et lesquels ouvrent une déclaration
 * de classe. Partagé pour qu'un garde-fou ne voie jamais un autre périmètre
 * que son voisin.
 */
final class PhpSources
{
    /**
     * Les jetons qui ouvrent une déclaration de classe, d'interface, de trait
     * ou d'enum. Seul `class` peut aussi ouvrir une classe anonyme (après
     * `new`) ou lire un nom (après `::`) ; chaque recenseur fait le tri.
     */
    public const array CLASS_LIKE = [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM];

    private const array IGNORED = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

    /**
     * @return list<string> chemins des fichiers `.php` du répertoire, récursivement, triés pour un diagnostic stable
     */
    public static function files(string $directory): array
    {
        $paths = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * @return list<PhpToken> les jetons du code, sans espaces ni commentaires
     */
    public static function significantTokens(string $code): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => !$token->is(self::IGNORED),
        ));
    }

    /**
     * Un jeton qui ouvre une accolade, que `}` refermera. `"{$a}"` l'ouvre par
     * T_CURLY_OPEN, dont le texte est `{`, et `"${a}"` par `${`.
     */
    public static function opensBrace(PhpToken $token): bool
    {
        return '{' === $token->text || $token->is(\T_DOLLAR_OPEN_CURLY_BRACES);
    }
}
