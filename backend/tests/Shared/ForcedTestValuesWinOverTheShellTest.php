<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;
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
 * résultats (CR19-6) : le run qui le lance tient le même répertoire de cache.
 * `--do-not-record-test-run-history`, non `--do-not-cache-result`, que
 * PHPUnit 13 déprécie : la relance rendrait « OK, but there were issues! ».
 */
final class ForcedTestValuesWinOverTheShellTest extends TestCase
{
    private const int TIMEOUT_SECONDS = 180;

    /** Le fichier où PHPUnit garde le résultat de chaque test, sous son `cacheDirectory`. */
    private const string RESULT_CACHE = 'test-run-history';

    public function testTheProbeStaysGreenUnderAShellThatExportsADummyValueForEachForcedName(): void
    {
        $dummies = [];
        $redactions = [];
        foreach (ForcedTestValuesProbeTest::forcedNames() as $name) {
            $dummies[$name] = bin2hex(random_bytes(16));
            $redactions[$dummies[$name]] = '<factice '.$name.'>';
        }

        $run = $this->nestedRun('testTheKernelAndAChildReadTheValuesThatPhpunitDistForces', $dummies);

        $output = strtr($run->getOutput().$run->getErrorOutput(), $redactions);
        self::assertSame(0, $run->getExitCode(), "La sonde rougit sous un shell qui exporte les noms forcés :\n".$output);
        self::assertStringContainsString('OK (1 test', $output, "La relance n'a pas exécuté la sonde :\n".$output);
    }

    public function testTheNestedRunLeavesTheResultCacheOfTheOuterRunUntouched(): void
    {
        $before = $this->resultCacheFingerprint();

        $run = $this->nestedRun('testEveryForcedServerValueIsForcedInEnvToo', []);

        $output = $run->getOutput().$run->getErrorOutput();
        self::assertStringContainsString('OK (1 test', $output, "La relance n'a pas exécuté son cas :\n".$output);
        self::assertSame($before, $this->resultCacheFingerprint(), 'Le phpunit imbriqué a réécrit le cache des résultats.');
    }

    /**
     * Un cas de la sonde, lancé par un `bin/phpunit` qui n'écrit aucun cache.
     *
     * Son propre environnement, à dessein (OWN_ENVIRONMENT de
     * ChildProcessHarnessTest) : celui que le test hérite, plus les valeurs
     * exportées, comme un shell qui les exporterait. La copie de `$_SERVER`
     * d'inTestEnvironment() y remettrait les valeurs de test.
     *
     * @param array<string, string> $exported
     */
    private function nestedRun(string $probeMethod, array $exported): Process
    {
        $probe = new ReflectionClass(ForcedTestValuesProbeTest::class)->getShortName();
        $run = new Process(
            ChildPhpProcess::command(
                'bin/phpunit',
                '--configuration',
                'phpunit.dist.xml',
                '--do-not-cache-test-index',
                '--do-not-record-test-run-history',
                '--colors=never',
                '--filter',
                $probe.'::'.$probeMethod,
            ),
            RepositoryFiles::backend(),
            $exported,
            timeout: self::TIMEOUT_SECONDS,
        );
        $run->run();

        return $run;
    }

    /**
     * L'empreinte du cache des résultats, sous le `cacheDirectory` de
     * phpunit.dist.xml, ou « absent ».
     */
    private function resultCacheFingerprint(): string
    {
        $configuration = new SimpleXMLElement(RepositoryFiles::read(RepositoryFiles::backend('phpunit.dist.xml')));
        $directory = (string) $configuration['cacheDirectory'];
        self::assertNotSame('', $directory, 'phpunit.dist.xml ne déclare plus de cacheDirectory.');
        $path = RepositoryFiles::backend($directory.'/'.self::RESULT_CACHE);

        return is_file($path) ? hash('sha256', RepositoryFiles::read($path)) : 'absent';
    }
}
