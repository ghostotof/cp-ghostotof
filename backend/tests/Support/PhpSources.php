<?php

declare(strict_types=1);

namespace App\Tests\Support;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ce que les recenseurs par jetons de src/ ont en commun (DeclaredClasses,
 * BareExceptionInstantiations) : quels fichiers ils lisent, et quels jetons
 * ils ignorent. Partagé pour qu'un garde-fou ne voie jamais un autre périmètre
 * que son voisin.
 */
final class PhpSources
{
    /**
     * Ce que les messages de correction des gardes de style (UnsortedImports,
     * MisqualifiedNativeCalls) font lancer dans le conteneur `backend` avant
     * la correction. php-cs-fixer n'est volontairement pas une dépendance
     * (#391) : son phar, à une version épinglée, se pose sous `var/`, qui
     * n'est pas versionné.
     */
    public const string CS_FIXER_DOWNLOAD = 'curl -fsSL -o var/php-cs-fixer.phar https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v3.95.27/php-cs-fixer.phar';

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
}
