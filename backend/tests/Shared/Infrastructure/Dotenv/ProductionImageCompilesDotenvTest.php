<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Dotenv;

use App\Tests\Support\ProductionDockerfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'image de production charge un `.env.local.php` compilé, jamais `.env`
 * analysé à chaque requête (issue #489).
 *
 * Sans ce fichier, chaque requête FPM et chaque commande relisaient et
 * analysaient `backend/.env` avec Dotenv. Le tableau PHP compilé, lui, reste
 * dans OPcache (`validate_timestamps = 0`). Le stage `production` le produit
 * par `composer dump-env prod`, dans l'instruction qui dispose encore de
 * Composer.
 *
 * Ce que le fichier peut contenir est borné par le contexte de build :
 * `dump-env prod` lit `.env`, `.env.local`, `.env.prod` et `.env.prod.local`.
 * Seul `.env` doit y entrer : il est versionné et ne porte aucune valeur
 * réelle. Les trois autres n'existent que sur un poste et peuvent en porter.
 * La priorité des variables du pod sur les valeurs compilées est vérifiée par
 * {@see CompiledDotenvNeverMasksPodVariablesTest}.
 */
final class ProductionImageCompilesDotenvTest extends TestCase
{
    private const string DUMP = 'composer dump-env prod';

    public function testTheProductionStageCompilesTheEnvironment(): void
    {
        $instruction = ProductionDockerfile::instructionContaining('post-install-cmd');
        $dump = strpos($instruction, self::DUMP);

        self::assertNotFalse($dump, 'Le stage production doit compiler .env par « composer dump-env prod ».');
        self::assertGreaterThan(strpos($instruction, 'post-install-cmd'), $dump, 'La compilation suit post-install-cmd.');

        $composerRemoval = strpos($instruction, 'rm -f /usr/local/bin/composer');
        self::assertNotFalse($composerRemoval);
        self::assertLessThan($composerRemoval, $dump, 'Composer doit encore être présent pour compiler.');
    }

    /**
     * Le RUN est déjà en `set -e` ({@see \App\Tests\Shared\Infrastructure\Cache\ProductionImageWarmUpTest}) :
     * seul un `||` pourrait avaler un échec de la compilation, et l'image
     * repartirait sur `.env` sans que rien ne le signale.
     */
    public function testAFailedCompilationFailsTheBuild(): void
    {
        self::assertSame(
            0,
            preg_match('/dump-env[^;]*\|\|/', ProductionDockerfile::instructionContaining(self::DUMP)),
            'Un « || » après dump-env avale l\'échec de la compilation.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function workstationOnlyDotenvFiles(): iterable
    {
        // Lus par `dump-env prod` en plus de `.env`.
        yield '.env.local' => ['backend/.env.local'];
        yield '.env.prod' => ['backend/.env.prod'];
        yield '.env.prod.local' => ['backend/.env.prod.local'];
        // Un fichier compilé sur un poste partirait tel quel avec `COPY backend/`
        // si la compilation du build venait à disparaître.
        yield '.env.local.php' => ['backend/.env.local.php'];
    }

    #[DataProvider('workstationOnlyDotenvFiles')]
    public function testAWorkstationDotenvFileNeverEntersTheBuildContext(string $path): void
    {
        $patterns = $this->dockerignorePatterns();

        self::assertSame([], array_values(array_filter(
            $patterns,
            static fn (string $pattern): bool => str_starts_with($pattern, '!'),
        )), 'Une exception « ! » du .dockerignore pourrait réintroduire un fichier exclu.');

        self::assertNotSame([], array_values(array_filter(
            $patterns,
            static fn (string $pattern): bool => fnmatch($pattern, $path, \FNM_PATHNAME),
        )), \sprintf('%s doit être exclu du contexte de build (.dockerignore).', $path));
    }

    /**
     * @return list<string>
     */
    private function dockerignorePatterns(): array
    {
        $path = \dirname(__DIR__, 5).'/.dockerignore';
        self::assertFileIsReadable($path);

        return array_values(array_filter(
            array_map(trim(...), explode("\n", (string) file_get_contents($path))),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'),
        ));
    }
}
