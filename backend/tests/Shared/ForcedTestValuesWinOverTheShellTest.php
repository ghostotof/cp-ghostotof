<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\RepositoryFiles;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\Configuration\Registry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * La menace de D1 (#748, dix-huitième relecture) de bout en bout : un
 * conteneur qui exporte une vraie clé ou un vrai DSN sous l'un des noms que
 * phpunit.dist.xml force. Relance la sonde ({@see ForcedTestValuesProbeTest})
 * dans un `bin/phpunit` dont l'environnement porte, pour chacun de ses noms,
 * une valeur factice aléatoire : la sonde doit y rester verte, le noyau du
 * test et son fils lisant la valeur de phpunit.dist.xml, non celle du shell.
 *
 * Les valeurs factices sont générées ici, jamais lues d'un fichier, et
 * n'apparaissent dans aucun message : la sortie de la relance n'est rendue
 * qu'une fois chacune remplacée par son nom.
 *
 * Le `bin/phpunit` imbriqué n'écrit ni l'index des tests ni l'historique des
 * résultats (CR19-6), et il écrit dans le répertoire de cache du run qui le
 * lance, lu de la configuration chargée : c'est ce répertoire que le cas
 * relève, fichier par fichier, avant et après la relance (R242 de la
 * vingtième relecture, St20-3). Un relevé qui ne dépend d'aucun nom de
 * fichier ne compare jamais « absent » à « absent » sans preuve, et le
 * répertoire doit exister avant la relance : PHPUnit le crée au démarrage, en
 * CI comme en local, alors que l'historique n'y est écrit qu'en fin de run.
 * `--do-not-record-test-run-history`, non `--do-not-cache-result`, que
 * PHPUnit 13 déprécie : la relance rendrait « OK, but there were issues! ».
 */
final class ForcedTestValuesWinOverTheShellTest extends TestCase
{
    private const int TIMEOUT_SECONDS = 180;

    public function testTheProbeStaysGreenUnderAShellExportingEachForcedNameAndLeavesTheOuterCacheUntouched(): void
    {
        $dummies = [];
        $redactions = [];
        foreach (ForcedTestValuesProbeTest::forcedNames() as $name) {
            $dummies[$name] = bin2hex(random_bytes(16));
            $redactions[$dummies[$name]] = '<factice '.$name.'>';
        }
        $cacheDirectory = $this->outerCacheDirectory();
        $before = $this->cacheSnapshot($cacheDirectory);

        $run = $this->nestedRun($cacheDirectory, $dummies);

        $output = strtr($run->getOutput().$run->getErrorOutput(), $redactions);
        self::assertSame(0, $run->getExitCode(), "La sonde rougit sous un shell qui exporte les noms forcés :\n".$output);
        self::assertStringContainsString('OK (1 test', $output, "La relance n'a pas exécuté la sonde :\n".$output);
        self::assertSame($before, $this->cacheSnapshot($cacheDirectory), 'Le phpunit imbriqué a écrit dans le cache du run externe.');
    }

    /**
     * La sonde, lancée par un `bin/phpunit` qui n'écrit aucun cache, dans le
     * répertoire de cache du run externe.
     *
     * Son propre environnement, à dessein (OWN_ENVIRONMENT de
     * ChildProcessHarnessTest) : celui que le test hérite, plus les valeurs
     * exportées, comme un shell qui les exporterait. La copie de `$_SERVER`
     * d'inTestEnvironment() y remettrait les valeurs de test.
     *
     * @param array<string, string> $exported
     */
    private function nestedRun(string $cacheDirectory, array $exported): Process
    {
        $probe = new ReflectionClass(ForcedTestValuesProbeTest::class)->getShortName();
        $run = new Process(
            ChildPhpProcess::command(
                'bin/phpunit',
                '--configuration',
                'phpunit.dist.xml',
                '--cache-directory',
                $cacheDirectory,
                '--do-not-cache-test-index',
                '--do-not-record-test-run-history',
                '--colors=never',
                '--filter',
                $probe.'::testTheKernelAndAChildReadTheValuesThatPhpunitDistForces',
            ),
            RepositoryFiles::backend(),
            $exported,
            timeout: self::TIMEOUT_SECONDS,
        );
        $run->run();

        return $run;
    }

    /**
     * Le répertoire de cache de la configuration que PHPUnit a chargée
     * ({@see Registry}, `@internal`, comme dans ChildPhpProcess), celui qui
     * tient l'historique du run externe ; il doit exister.
     */
    private function outerCacheDirectory(): string
    {
        $configuration = Registry::get();
        self::assertTrue($configuration->hasCacheDirectory(), 'La configuration chargée ne déclare plus de cacheDirectory.');
        $directory = $configuration->cacheDirectory();
        self::assertSame($directory, \dirname($configuration->testRunHistoryFile()), "L'historique du run n'est pas dans son cache.");
        self::assertDirectoryExists($directory, 'Le répertoire de cache du run externe est absent avant la relance.');

        return $directory;
    }

    /**
     * Chaque fichier du répertoire de cache, récursivement : chemin relatif
     * => sha256 du contenu, trié.
     *
     * @return array<string, string>
     */
    private function cacheSnapshot(string $directory): array
    {
        $snapshot = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $path = $file->getPathname();
                $snapshot[substr($path, \strlen($directory) + 1)] = hash('sha256', RepositoryFiles::read($path));
            }
        }
        ksort($snapshot);

        return $snapshot;
    }
}
