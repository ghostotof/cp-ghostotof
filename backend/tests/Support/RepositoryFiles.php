<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Les deux racines d'où un test lit un fichier du dépôt, et la lecture
 * elle-même (issue #629). Écrites ici seulement, depuis l'emplacement de
 * cette classe : un test qui les recalculait par `\dirname(__DIR__, n)` ou
 * `__DIR__.'/../..'` dépendait du sien, et un déplacement lisait ailleurs.
 * {@see \App\Tests\Support\PathsLeavingTests} refuse ce calcul partout
 * ailleurs dans `tests/`.
 */
final class RepositoryFiles
{
    /**
     * La racine du dépôt, le contexte de build des images, ou un chemin
     * relatif sous elle. Le conteneur de dev n'en monte qu'une partie
     * (docker-compose.yml), la CI extrait tout.
     */
    public static function repository(string $path = ''): string
    {
        return self::under(\dirname(self::backend()), $path);
    }

    /**
     * La racine du backend, `getProjectDir()` du noyau de l'application, ou
     * un chemin relatif sous elle (`backend('src')`).
     */
    public static function backend(string $path = ''): string
    {
        return self::under(\dirname(__DIR__, 2), $path);
    }

    /**
     * Le texte d'un fichier, par son chemin absolu. Un fichier absent, qui
     * n'est pas un fichier ordinaire (un répertoire passe
     * `assertFileIsReadable`) ou illisible fait échouer le test en le
     * nommant, chaque cause par son message, et une lecture ratée n'est
     * jamais rendue comme un texte vide (issue #615).
     */
    public static function read(string $path): string
    {
        Assert::assertTrue(file_exists($path), \sprintf('%s : fichier absent.', $path));
        Assert::assertTrue(is_file($path), \sprintf('%s : pas un fichier ordinaire.', $path));
        Assert::assertTrue(is_readable($path), \sprintf('%s : fichier illisible.', $path));
        $content = file_get_contents($path);
        Assert::assertIsString($content, \sprintf('%s : lecture impossible.', $path));

        return $content;
    }

    private static function under(string $root, string $path): string
    {
        return '' === $path ? $root : $root.'/'.$path;
    }
}
