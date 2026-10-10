<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\UnsortedImports;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les `use` de `src/` et de `tests/` restent triés (issue #394), dans l'ordre
 * de php-cs-fixer `ordered_imports` `alpha` que #391 a appliqué une fois.
 * Aucun outil du projet ne l'impose, et `rector:fix` ajoute chaque nouvel
 * import en tête du bloc.
 *
 * Le recensement est d'abord éprouvé sur des extraits littéraux. Un recenseur
 * qui ne trouverait jamais rien rendrait le premier test vert sans rien
 * garder. Les cas « séparateur » et « alias » sont ceux où un tri naïf
 * s'écarte de php-cs-fixer.
 */
final class ImportsStaySortedTest extends TestCase
{
    public function testEveryImportBlockIsSorted(): void
    {
        $root = \dirname(__DIR__, 2);

        self::assertSame([], UnsortedImports::in($root.'/src', $root.'/tests'), UnsortedImports::FIX);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('snippets')]
    public function testTheCensusReportsEveryMisplacedOrForbiddenImport(string $code, array $expected): void
    {
        self::assertSame($expected, UnsortedImports::inCode($code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function snippets(): iterable
    {
        yield 'bloc trié' => ["<?php\nnamespace App;\nuse App\\Alpha;\nuse Symfony\\Beta;\n", []];
        yield 'bloc en désordre' => ["<?php\nnamespace App;\nuse Symfony\\Beta;\nuse App\\Alpha;\n", ['4 App\\Alpha doit précéder Symfony\\Beta']];
        yield 'casse indifférente' => ["<?php\nnamespace App;\nuse App\\Alpha;\nuse App\\beta;\n", []];
        yield 'casse indifférente, en désordre' => ["<?php\nnamespace App;\nuse App\\beta;\nuse App\\Alpha;\n", ['4 App\\Alpha doit précéder App\\beta']];
        // `\` compte comme une espace : `Foo\Bar` passe avant `Foo2`, alors que l'octet `\` suit le chiffre.
        yield 'séparateur de segment' => ["<?php\nnamespace App;\nuse App\\Foo\\Bar;\nuse App\\Foo2;\n", []];
        yield 'séparateur de segment, en désordre' => ["<?php\nnamespace App;\nuse App\\Foo2;\nuse App\\Foo\\Bar;\n", ['4 App\\Foo\\Bar doit précéder App\\Foo2']];
        // L'alias fait partie de la clé : « Foo Alpha » précède « Foo as Bar ».
        yield 'alias dans la clé' => ["<?php\nnamespace App;\nuse App\\Foo\\Alpha;\nuse App\\Foo as Bar;\n", []];
        yield 'alias dans la clé, en désordre' => ["<?php\nnamespace App;\nuse App\\Foo as Bar;\nuse App\\Foo\\Alpha;\n", ['4 App\\Foo\\Alpha doit précéder App\\Foo as Bar']];
        yield 'liste à virgules' => ["<?php\nnamespace App;\nuse Zed, Alpha;\n", ['3 Alpha doit précéder Zed']];
        yield 'blocs séparés par du code' => ["<?php\nnamespace App;\nuse Zed;\nconst X = 1;\nuse Alpha;\n", []];
        yield 'use de trait' => ["<?php\nnamespace App;\nfinal class X\n{\n    use Zed;\n    use Alpha;\n}\n", []];
        yield 'use de fonction anonyme' => ["<?php\nnamespace App;\n\$f = function () use (\$b, \$a) {};\n", []];
        yield 'use de trait après une chaîne interpolée' => ["<?php\nnamespace App;\n\$s = \"{\$a}\";\nfinal class X\n{\n    use Zed;\n    use Alpha;\n}\n", []];
        yield 'use function, interdit' => ["<?php\nnamespace App;\nuse function sprintf;\n", ['3 use function interdit']];
        yield 'use const, interdit' => ["<?php\nnamespace App;\nuse const PHP_EOL;\n", ['3 use const interdit']];
        yield 'import groupé, interdit' => ["<?php\nnamespace App;\nuse App\\{Beta, Alpha};\n", ['3 import groupé interdit']];
    }
}
