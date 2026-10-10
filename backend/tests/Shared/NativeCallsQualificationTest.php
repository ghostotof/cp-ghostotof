<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\MisqualifiedNativeCalls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * Une fonction native de `src/` ou de `tests/` est qualifiée exactement quand
 * le compilateur l'optimise (issue #394, règle de #391). Celles de l'ensemble
 * `@compiler_optimized` de php-cs-fixer s'écrivent avec leur `\` initial, les
 * autres sans. La raison est mesurée : `\sprintf('x %s', $a)` est compilé en un
 * simple `FAST_CONCAT`, alors que `sprintf(…)` non qualifié est résolu à
 * l'exécution, puis appelé.
 *
 * Le recensement est d'abord éprouvé sur des extraits littéraux. Un recenseur
 * qui ne trouverait jamais rien rendrait le premier test vert sans rien
 * garder.
 */
final class NativeCallsQualificationTest extends TestCase
{
    public function testEveryNativeCallIsQualifiedExactlyWhenTheCompilerOptimizesIt(): void
    {
        $root = \dirname(__DIR__, 2);

        self::assertSame([], MisqualifiedNativeCalls::in($root.'/src', $root.'/tests'), MisqualifiedNativeCalls::FIX);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('snippets')]
    public function testTheCensusReportsEveryMisqualifiedCall(string $code, array $expected): void
    {
        self::assertSame($expected, MisqualifiedNativeCalls::inCode($code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function snippets(): iterable
    {
        yield 'optimisée, qualifiée' => ["<?php\nnamespace App;\n\$a = \\sprintf('%s', 1);\n", []];
        yield 'optimisée, non qualifiée' => ["<?php\nnamespace App;\n\$a = sprintf('%s', 1);\n", ['3 sprintf() doit s\'écrire \\sprintf()']];
        yield 'non optimisée, non qualifiée' => ["<?php\nnamespace App;\n\$a = array_map(\$f, []);\n", []];
        yield 'non optimisée, qualifiée' => ["<?php\nnamespace App;\n\$a = \\array_map(\$f, []);\n", ['3 \\array_map() doit s\'écrire array_map()']];
        yield 'casse indifférente, comme PHP' => ["<?php\nnamespace App;\n\$a = Count([]);\n", ['3 Count() doit s\'écrire \\Count()']];
        yield 'fichier sans espace de noms' => ["<?php\n\$a = count([]);\n", ['2 count() doit s\'écrire \\count()']];
        yield 'ligne exacte' => ["<?php\nnamespace App;\n\$a = [\n    \\count([]),\n    strlen('x'),\n];\n", ['5 strlen() doit s\'écrire \\strlen()']];
        yield 'callable de première classe' => ["<?php\nnamespace App;\n\$a = is_string(...);\n\$b = \\array_map(...);\n", []];
        yield 'méthode' => ["<?php\nnamespace App;\n\$a = \$o->count();\n\$b = \$o?->count();\n\$c = Foo::count();\n", []];
        yield 'déclaration de méthode' => ["<?php\nnamespace App;\nfinal class X\n{\n    public function count(): int\n    {\n        return 0;\n    }\n}\n", []];
        yield 'déclaration par référence' => ["<?php\nnamespace App;\nfunction &count(): array\n{\n}\n", []];
        yield 'instanciation' => ["<?php\nnamespace App;\n\$a = new Count(1);\n\$b = new \\Count(1);\n", []];
        yield 'attribut' => ["<?php\nnamespace App;\n#[Count(1)]\n#[\\Attribute(1)]\nfinal class X {}\n", []];
        yield 'deuxième attribut du groupe' => ["<?php\nnamespace App;\n#[Foo(1, 2), Count(1)]\nfinal class X {}\n", []];
        yield 'appel après un attribut' => ["<?php\nnamespace App;\n#[Foo(1)]\nfunction x(): int\n{\n    return count([]);\n}\n", ['6 count() doit s\'écrire \\count()']];
        yield 'fonction d\'un espace de noms' => ["<?php\nnamespace App;\n\$a = Foo\\count([]);\n\$b = \\App\\count([]);\n", []];
        yield 'argument nommé' => ["<?php\nnamespace App;\n\$a = foo(count: 1);\n", []];
    }

    /**
     * La liste est recopiée de php-cs-fixer, qui la tient pour toutes les
     * versions de PHP. Un nom qui n'est plus une fonction interne doit être
     * déclaré absent, et un nom déclaré absent ne doit pas exister : la liste
     * ne dérive pas en silence d'une version de PHP à l'autre.
     */
    public function testTheCompilerOptimizedListMatchesTheRunningPhp(): void
    {
        $list = MisqualifiedNativeCalls::COMPILER_OPTIMIZED;
        self::assertCount(43, array_unique($list));

        $notInternal = array_values(array_filter(
            $list,
            static fn (string $name): bool => !\function_exists($name) || !new ReflectionFunction($name)->isInternal(),
        ));
        self::assertSame(MisqualifiedNativeCalls::ABSENT_FROM_PHP, $notInternal);
    }
}
