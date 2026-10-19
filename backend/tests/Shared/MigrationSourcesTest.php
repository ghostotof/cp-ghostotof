<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\MigrationSources;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Ce que {@see MigrationSources} lit d'une migration (issue #615) : le
 * fichier vient de la racine du backend, pas d'un chemin relatif au test qui
 * le demande, et un fichier absent fait échouer le test en le nommant.
 */
final class MigrationSourcesTest extends TestCase
{
    /**
     * Lire une vraie migration prouve aussi que la racine résolue est celle
     * du backend.
     */
    public function testAMigrationIsReadByItsVersion(): void
    {
        self::assertStringContainsString(
            'final class Version20261011120000 extends AbstractMigration',
            MigrationSources::of('Version20261011120000'),
        );
    }

    /**
     * Avant #615, `file_get_contents` rendait `false`, converti en `''`, et le
     * test échouait plus loin sur un index absent, sans nommer le fichier.
     */
    public function testAMissingMigrationFailsTheTestNamingTheFile(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('#/migrations/Version19700101000000\.php : fichier absent, illisible ou qui n\'est pas un fichier ordinaire\.#');

        MigrationSources::of('Version19700101000000');
    }
}
