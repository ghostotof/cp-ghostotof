<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\ErrorHandler;

/**
 * Le collecteur de dépréciations d'API Platform rend ce qu'il ne retient pas à
 * PHPUnit **sans changer la façon dont PHPUnit le classe** (issue #701).
 *
 * `PHPUnit\Runner\ErrorHandler` classe une `E_USER_DEPRECATED` « directe » ou
 * « indirecte » (donc comptée ou non, `ignoreIndirectDeprecations`) d'après les
 * deux premières trames de la pile, une fois retirées les siennes
 * (`errorStackTrace()` : les trames dont la classe est `ErrorHandler`). Pour
 * un `trigger_error()` brut — sans `trigger_deprecation()`, dont PHPUnit
 * repère la trame —, la trame de la closure du collecteur restait en tête :
 * `DefaultResolver` n'y lisait pas de fichier d'appelant, classait la
 * dépréciation « inconnue », et PHPUnit comptait comme un échec ce qu'il
 * ignore d'ordinaire.
 *
 * La classe ne se mesure que dans un vrai PHPUnit : celui-ci en lance un
 * enfant sur `RawIndirectDeprecationProbe`, qui lève la même dépréciation
 * indirecte avec et sans collecteur, et exige qu'aucune ne soit comptée.
 *
 * La liaison à `PHPUnit\Runner\ErrorHandler` (`@internal` à PHPUnit) se replie
 * sans bruit sur la closure telle quelle si cette classe disparaît ou est
 * renommée : le premier test ci-dessous est ce qui le dit.
 */
final class ApiPlatformDeprecationHandoverTest extends TestCase
{
    /**
     * Le repli de `ApiPlatformDeprecations::during()` est silencieux : sans
     * `ErrorHandler`, il rend la closure non liée et ne dit rien. Ce test est
     * la voix qui lui manque, et il nomme la cause avant que l'enfant ne
     * compte la dépréciation.
     */
    public function testPhpunitsErrorHandlerIsThereForTheCollectorToBindTo(): void
    {
        self::assertTrue(
            class_exists(ErrorHandler::class, false),
            'PHPUnit\\Runner\\ErrorHandler n\'est plus chargé (renommé ou retiré) : ApiPlatformDeprecations::during() retombe en silence sur sa closure non liée, '
            .'et une dépréciation indirecte brute est de nouveau comptée. Relire ApiPlatformDeprecations::asPhpunitReadsIt().',
        );
    }

    public function testAnIndirectDeprecationStaysIndirectForPhpunitUnderTheCollector(): void
    {
        $process = ChildPhpProcess::inTestEnvironment(
            60,
            RepositoryFiles::backend('vendor/bin/phpunit'),
            '--configuration',
            RepositoryFiles::backend('phpunit.dist.xml'),
            '--no-progress',
            '--colors=never',
            RepositoryFiles::backend('tests/Shared/Infrastructure/ApiPlatform/Fixtures/RawIndirectDeprecationProbe.php'),
        );
        $exitCode = $process->run();

        self::assertStringContainsString('OK (2 tests', $process->getOutput(), "Le PHPUnit enfant a compté la dépréciation indirecte :\n".$process->getOutput().$process->getErrorOutput());
        self::assertSame(0, $exitCode);
    }
}
