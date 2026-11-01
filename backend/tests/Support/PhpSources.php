<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;
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
     * Les sites que trouve un recenseur dans chaque fichier du répertoire.
     *
     * @param Closure(string): list<int> $linesIn les lignes des sites d'un code source
     *
     * @return list<string> `chemin:ligne` de chaque site, triés par fichier pour un diagnostic stable
     */
    public static function sites(string $directory, Closure $linesIn): array
    {
        $found = [];
        foreach (self::files($directory) as $path) {
            foreach ($linesIn(RepositoryFiles::read($path)) as $line) {
                $found[] = $path.':'.$line;
            }
        }

        return $found;
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
     * Un jeton qui ouvre une accolade, que {@see self::closesBrace()} refermera :
     * l'accolade du code (identifiant `{`), `"{$a}"` par T_CURLY_OPEN et `"${a}"`
     * par `${`. Lu par l'identifiant, jamais par le texte : le fragment d'une
     * chaîne interpolée (T_ENCAPSED_AND_WHITESPACE) peut avoir le texte `{`, et
     * `"$x{"` ou `"{\"id\":$id}"` n'ouvrent rien (issue #748, C15-2).
     */
    public static function opensBrace(PhpToken $token): bool
    {
        return self::isChar($token, '{') || \T_CURLY_OPEN === $token->id || \T_DOLLAR_OPEN_CURLY_BRACES === $token->id;
    }

    /**
     * Un jeton qui ferme une accolade ouverte par {@see self::opensBrace()} : le
     * `}` du code, jamais le fragment `}` d'une chaîne interpolée
     * (`"$x}"`, `"{\"id\":$id}"`, `"\{$a}"`), dont le texte seul est le même.
     */
    public static function closesBrace(PhpToken $token): bool
    {
        return self::isChar($token, '}');
    }

    /**
     * Le jeton ouvre-t-il un niveau d'imbrication : `(`, `[` ou une accolade
     * ({@see self::opensBrace()}) ? Lu par l'identifiant : le fragment `(` d'une
     * chaîne interpolée n'en ouvre aucun.
     */
    public static function opensALevel(PhpToken $token): bool
    {
        return self::isAnyChar($token, '(', '[') || self::opensBrace($token);
    }

    /** Le jeton ferme-t-il un niveau ouvert par {@see self::opensALevel()} : `)`, `]` ou `}` du code ? */
    public static function closesALevel(PhpToken $token): bool
    {
        return self::isAnyChar($token, ')', ']') || self::closesBrace($token);
    }

    /**
     * Le jeton est-il ce caractère du code (`(`, `)`, `[`, `]`, `,`, `;`, `$`…) ?
     * Lu par l'identifiant, que seul le jeton d'un caractère du code porte :
     * `PhpToken::is('(')` compare le texte, que le fragment `(` d'une chaîne
     * interpolée (`"$x("`) a aussi. Pas pour `&` : depuis PHP 8.1 il a deux
     * identifiants propres (`T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG` et
     * `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`), jamais le sien.
     *
     * @param non-empty-string $char un seul caractère
     */
    public static function isChar(PhpToken $token, string $char): bool
    {
        return \ord($char[0]) === $token->id;
    }

    /**
     * Le jeton est-il l'un de ces caractères du code ? Lu par l'identifiant,
     * comme {@see self::isChar()}.
     *
     * @param non-empty-string ...$chars un seul caractère chacun
     */
    public static function isAnyChar(PhpToken $token, string ...$chars): bool
    {
        return array_any($chars, static fn (string $char): bool => self::isChar($token, $char));
    }
}
