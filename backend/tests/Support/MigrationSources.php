<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Le source d'une migration Doctrine, lu depuis la racine du backend (issue
 * #615) : le chemin ne dépend pas de l'emplacement du test qui le demande.
 *
 * Les migrations ne sont pas chargées par l'autoloader
 * (`doctrine_migrations.yaml`), la réflexion ne trouve donc pas leur fichier ;
 * et une migration est figée, elle n'expose aucune constante qu'un test
 * pourrait lire à la place de son texte.
 */
final class MigrationSources
{
    /**
     * Le texte de `migrations/<version>.php`. Un fichier absent, illisible
     * ou qui n'est pas un fichier ordinaire (un répertoire passe
     * `assertFileIsReadable`) fait échouer le test en le nommant, et une
     * lecture ratée n'est jamais rendue comme un texte vide.
     */
    public static function of(string $version): string
    {
        $path = \dirname(__DIR__, 2).'/migrations/'.$version.'.php';
        Assert::assertTrue(is_file($path) && is_readable($path), \sprintf('%s : migration absente ou illisible.', $path));
        $source = file_get_contents($path);
        Assert::assertIsString($source, \sprintf('%s : lecture impossible.', $path));

        return $source;
    }
}
