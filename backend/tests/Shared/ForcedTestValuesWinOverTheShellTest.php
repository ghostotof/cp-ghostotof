<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Process\Process;

/**
 * La menace de D1 (#748, dix-huitième relecture) de bout en bout : un
 * conteneur qui exporte une vraie clé sous l'un des noms que phpunit.dist.xml
 * force. Relance la sonde ({@see ForcedTestValuesProbeTest}) dans un
 * `bin/phpunit` dont l'environnement porte, pour chacun de ses noms, une
 * valeur factice aléatoire : la sonde doit y rester verte, le noyau du test
 * et son fils lisant la valeur de phpunit.dist.xml, non celle du shell.
 *
 * Les valeurs factices sont générées ici, jamais lues d'un fichier, et
 * n'apparaissent dans aucun message : la sortie de la relance n'est rendue
 * qu'une fois chacune remplacée par son nom.
 */
final class ForcedTestValuesWinOverTheShellTest extends TestCase
{
    private const int TIMEOUT_SECONDS = 180;

    public function testTheProbeStaysGreenUnderAShellThatExportsADummyValueForEachForcedName(): void
    {
        $dummies = [];
        $redactions = [];
        foreach (ForcedTestValuesProbeTest::NAMES as $name) {
            $dummies[$name] = bin2hex(random_bytes(16));
            $redactions[$dummies[$name]] = '<factice '.$name.'>';
        }
        $probe = new ReflectionClass(ForcedTestValuesProbeTest::class)->getShortName();

        // Son propre environnement, à dessein (OWN_ENVIRONMENT de
        // ChildProcessHarnessTest) : celui que le test hérite, plus les
        // valeurs factices, comme un shell qui les exporterait. La copie de
        // `$_SERVER` d'inTestEnvironment() y remettrait les valeurs de test.
        $run = new Process(
            ChildPhpProcess::command(
                'bin/phpunit',
                '--configuration',
                'phpunit.dist.xml',
                '--do-not-cache-test-index',
                '--colors=never',
                '--filter',
                $probe,
            ),
            RepositoryFiles::backend(),
            $dummies,
            timeout: self::TIMEOUT_SECONDS,
        );
        $run->run();

        $output = strtr($run->getOutput().$run->getErrorOutput(), $redactions);
        self::assertSame(0, $run->getExitCode(), "La sonde rougit sous un shell qui exporte les noms forcés :\n".$output);
        self::assertStringContainsString('OK (1 test', $output, "La relance n'a pas exécuté la sonde :\n".$output);
    }
}
