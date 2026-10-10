<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Manifest;

/**
 * L'écriture commune aux deux builders de manifeste : créer le répertoire si
 * besoin, puis écrire le fichier, et arrêter la construction si l'un des deux
 * échoue (issue #338). `file_put_contents` ne lève pas : sans contrôle de son
 * retour, un disque plein laissait partir une image sans manifeste.
 */
trait WritesManifestFile
{
    /**
     * @throws ManifestWriteException
     */
    private static function writeManifestFile(string $path, string $content): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw ManifestWriteException::forDirectory($directory);
        }

        if (false === file_put_contents($path, $content)) {
            throw ManifestWriteException::forFile($path);
        }
    }
}
