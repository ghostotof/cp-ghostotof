<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Les deux racines d'où un test lit un fichier du dépôt, et la lecture
 * elle-même (issue #629). Calculées ici une fois, depuis l'emplacement de
 * cette classe : un test qui les recalculait par `\dirname(__DIR__, n)` ou
 * `__DIR__.'/../..'` dépendait du sien, et un déplacement lisait ailleurs.
 * PathsLeavingTests refuse ce calcul partout ailleurs dans `tests/`.
 */
final class RepositoryFiles
{
    /**
     * La racine du dépôt : le contexte de build des images. Le conteneur de
     * dev n'en monte qu'une partie (docker-compose.yml), la CI extrait tout.
     */
    public static function repository(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * La racine du backend, `getProjectDir()` du noyau de l'application.
     */
    public static function backend(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Le texte d'un fichier, par son chemin absolu. Un fichier absent,
     * illisible ou qui n'est pas un fichier ordinaire (un répertoire passe
     * `assertFileIsReadable`) fait échouer le test en le nommant, et une
     * lecture ratée n'est jamais rendue comme un texte vide (issue #615).
     */
    public static function read(string $path): string
    {
        Assert::assertTrue(is_file($path) && is_readable($path), \sprintf('%s : fichier absent, illisible ou qui n\'est pas un fichier ordinaire.', $path));
        $content = file_get_contents($path);
        Assert::assertIsString($content, \sprintf('%s : lecture impossible.', $path));

        return $content;
    }
}
