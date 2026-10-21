<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Dotenv;

use App\Tests\Support\PhpImageBuild;
use App\Tests\Support\RepositoryFiles;
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
 * Composer, et sous `env -i` : Dotenv résout un `${VAR}` de `.env` dans
 * `$_SERVER` avant de laisser Flex le différer à l'exécution. Une variable du
 * build homonyme (un `ARG`, un `ENV`, celles de l'image de base) serait sinon
 * figée dans l'image.
 *
 * Ce que le fichier peut contenir est borné par le contexte de build :
 * `dump-env prod` lit `.env`, `.env.local`, `.env.prod` et `.env.prod.local`.
 * Seul `.env` doit y entrer : il est versionné et ne porte aucune valeur
 * réelle. Les trois autres n'existent que sur un poste et peuvent en porter.
 * {@see CompiledDotenvNeverMasksPodVariablesTest} exécute cette commande même
 * et vérifie le fichier qu'elle produit.
 */
final class ProductionImageCompilesDotenvTest extends TestCase
{
    /**
     * La commande exacte : ni `--empty` (le fichier ne garderait que
     * `APP_ENV`, et toutes les valeurs par défaut de `.env` disparaîtraient),
     * ni `||`, `&` ou `if` qui avaleraient son échec. Le RUN est en `set -e`
     * ({@see \App\Tests\Shared\Infrastructure\Cache\ProductionImageWarmUpTest}).
     */
    public const string DUMP_COMMAND = 'env -i PATH="$PATH" HOME="$HOME" COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod';

    /**
     * Les seules variables que la compilation voit : Composer en a besoin
     * pour démarrer.
     */
    private const array KEPT_VARIABLES = ['PATH', 'HOME', 'COMPOSER_ALLOW_SUPERUSER'];

    public function testTheProductionStageCompilesTheEnvironmentAlone(): void
    {
        self::assertSame(self::DUMP_COMMAND, PhpImageBuild::commandContaining('dump-env'));

        $instruction = PhpImageBuild::instructionContaining('dump-env');
        $dumpOffset = strpos($instruction, self::DUMP_COMMAND);
        $warmUpOffset = strpos($instruction, 'post-install-cmd');
        $composerRemovalOffset = strpos($instruction, 'rm -f /usr/local/bin/composer');

        self::assertNotFalse($warmUpOffset, 'La compilation partage l\'instruction du préchauffage.');
        self::assertNotFalse($composerRemovalOffset);
        self::assertGreaterThan($warmUpOffset, $dumpOffset, 'La compilation suit post-install-cmd.');
        self::assertLessThan($composerRemovalOffset, $dumpOffset, 'Composer doit encore être présent pour compiler.');
    }

    /**
     * Un `rm`, un `cp` ou un `echo` sur le fichier compilé, plus loin dans le
     * stage ou dans `preprod`, déferait la compilation sans bruit.
     */
    public function testNoOtherInstructionTouchesTheCompiledFile(): void
    {
        self::assertSame([], array_values(array_filter(
            PhpImageBuild::instructions(),
            static fn (string $instruction): bool => str_contains($instruction, '.env.local.php'),
        )));
    }

    public function testNoDotenvReferenceNamesAVariableTheCompilationKeeps(): void
    {
        $references = $this->dotenvReferences();

        self::assertNotSame([], $references, 'Aucune référence ${…} relevée dans backend/.env : le relevé est cassé.');
        self::assertSame([], array_values(array_intersect($references, self::KEPT_VARIABLES)), 'Cette référence serait résolue au build, avec la valeur du build.');
    }

    public function testNoExceptionReincludesAnExcludedFile(): void
    {
        self::assertSame([], array_values(array_filter(
            PhpImageBuild::dockerignorePatterns(),
            static fn (string $pattern): bool => str_starts_with($pattern, '!'),
        )), 'Une exception « ! » du .dockerignore pourrait réintroduire un fichier exclu.');
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

    /**
     * `fnmatch()` n'est qu'une approximation du moteur de Docker (Go
     * `filepath.Match`, `**`, `/` initial retiré) : elle suffit aux motifs
     * littéraux du dépôt. Un motif réécrit dans une forme qu'elle ne lit pas
     * fait échouer le test, il ne le fait pas passer à tort.
     */
    #[DataProvider('workstationOnlyDotenvFiles')]
    public function testAWorkstationDotenvFileNeverEntersTheBuildContext(string $path): void
    {
        self::assertNotSame([], array_values(array_filter(
            PhpImageBuild::dockerignorePatterns(),
            static fn (string $pattern): bool => fnmatch($pattern, $path, \FNM_PATHNAME),
        )), \sprintf('%s doit être exclu du contexte de build (.dockerignore).', $path));
    }

    /**
     * Les variables que `backend/.env` référence par `$NAME` ou `${NAME}`.
     * Une valeur entre apostrophes n'est pas interpolée, un `\$` non plus.
     *
     * @return list<string>
     */
    private function dotenvReferences(): array
    {
        $references = [];
        foreach (explode("\n", RepositoryFiles::read(RepositoryFiles::backend('.env'))) as $line) {
            if (1 !== preg_match('/^\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*=(.*)\z/', $line, $assignment) || str_starts_with($assignment[1], "'")) {
                continue;
            }
            preg_match_all('/(?<!\\\\)\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $assignment[1], $names);
            array_push($references, ...$names[1]);
        }

        return array_values(array_unique($references));
    }
}
