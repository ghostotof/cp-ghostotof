<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\PathsLeavingTests;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un chemin que `tests/` bâtit depuis `__DIR__` reste dans `tests/` (issue
 * #629) : une fixture voisine, `tests/bootstrap.php`. Un fichier de `src/`,
 * `config/`, du backend ou du dépôt se lit depuis {@see RepositoryFiles},
 * dont les racines ne dépendent pas de l'emplacement du test : un
 * `\dirname(__DIR__, 4)` lisait ailleurs dès que son test changeait de
 * profondeur, et un `(string) file_get_contents()` rendait alors `''` sans
 * rien nommer (issue #615).
 *
 * Le recensement est d'abord éprouvé sur des extraits littéraux. Un recenseur
 * qui ne trouverait jamais rien rendrait le premier test vert sans rien
 * garder.
 */
final class DirPathsStayInTestsTest extends TestCase
{
    /**
     * Les fichiers qui gardent leur calcul, chemin relatif au backend, avec
     * leur raison. Chacun doit encore en avoir besoin.
     */
    private const array EXEMPT = [
        // La racine elle-même : le seul calcul que les autres remplacent.
        'tests/Support/RepositoryFiles.php',
        // Le bootstrap de PHPUnit charge l'autoloader : RepositoryFiles
        // n'existe pas encore pour lui.
        'tests/bootstrap.php',
        // Le point d'entrée de phpstan-doctrine, même raison.
        'tests/object-manager.php',
        // Un script exécuté en processus enfant, qui charge l'autoloader
        // lui-même avant toute classe.
        'tests/Shared/Fixtures/ChildProcessHarness/await-barrier.php',
    ];

    public function testNoPathBuiltFromDirLeavesTests(): void
    {
        $found = array_values(array_filter(
            PathsLeavingTests::in(RepositoryFiles::backend().'/tests'),
            static fn (string $problem): bool => !\in_array(strstr($problem, ':', true), self::EXEMPT, true),
        ));

        self::assertSame([], $found, 'Lire depuis RepositoryFiles::backend() ou ::repository(), jamais depuis l\'emplacement du test.');
    }

    public function testEveryExemptionIsStillNeeded(): void
    {
        $files = array_map(
            static fn (string $problem): string => (string) strstr($problem, ':', true),
            PathsLeavingTests::in(RepositoryFiles::backend().'/tests'),
        );

        self::assertSame([], array_values(array_diff(self::EXEMPT, $files)), 'Exemption devenue inutile : la retirer.');
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('snippets')]
    public function testTheCensusReportsEveryPathLeavingTests(string $directory, string $code, array $expected): void
    {
        self::assertSame($expected, PathsLeavingTests::inCode($code, $directory));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function snippets(): iterable
    {
        yield 'dirname jusqu\'au backend' => ['tests/Shared', "<?php\n\$a = \\dirname(__DIR__, 2).'/src';\n", ['2 src : hors de tests/']];
        yield 'dirname jusqu\'à la racine du dépôt' => ['tests/Support', "<?php\n\$a = \\dirname(__DIR__, 3).'/k8s/base';\n", ['2 ../k8s/base : hors de tests/']];
        yield 'dirname sans littéral' => ['tests/Shared', "<?php\n\$a = \\dirname(__DIR__, 2);\n", ['2 . : hors de tests/']];
        yield 'dirname non qualifié, casse indifférente' => ['tests/Shared', "<?php\n\$a = Dirname(__DIR__, 2);\n", ['2 . : hors de tests/']];
        yield 'dirname sans niveau, dans tests' => ['tests/Shared', "<?php\n\$a = \\dirname(__DIR__);\n", []];
        yield 'dirname qui redescend dans tests' => ['tests/Security/User/Fixtures', "<?php\nrequire \\dirname(__DIR__, 4).'/tests/bootstrap.php';\n", []];
        yield 'dirname au niveau non littéral' => ['tests/Shared', "<?php\n\$a = \\dirname(__DIR__, \$n);\n", ['2 \\dirname(__DIR__, …) : niveau non littéral']];
        yield 'remontée relative hors de tests' => ['tests/Shared', "<?php\n\$a = __DIR__.'/../../src';\n", ['2 src : hors de tests/']];
        yield 'remontée entre guillemets doubles' => ['tests/Shared', "<?php\n\$a = __DIR__.\"/../../composer.json\";\n", ['2 composer.json : hors de tests/']];
        yield 'remontée dans une constante' => ['tests/Shared/Infrastructure/Http', "<?php\nfinal class X\n{\n    private const string SOURCES = __DIR__.'/../../../../src';\n}\n", ['4 src : hors de tests/']];
        yield 'fixture voisine' => ['tests/Security/User/Application', "<?php\n\$a = __DIR__.'/../Fixtures/invite-account.php';\n", []];
        yield '__DIR__ seul' => ['tests/Shared', "<?php\n\$a = __DIR__;\n\$b = __DIR__.'/Fixtures';\n", []];
        yield 'méthode homonyme' => ['tests/Shared', "<?php\n\$a = \$o->dirname(__DIR__, 2);\n\$b = Foo::dirname(__DIR__, 2);\n", []];
        yield 'commentaire et chaîne' => ['tests/Shared', "<?php\n// \\dirname(__DIR__, 2).'/src'\n\$a = '__DIR__/../../src';\n", []];
        yield 'ligne exacte' => ['tests/Shared', "<?php\n\$a = [\n    __DIR__,\n    \\dirname(__DIR__, 2).'/config',\n];\n", ['4 config : hors de tests/']];
    }
}
