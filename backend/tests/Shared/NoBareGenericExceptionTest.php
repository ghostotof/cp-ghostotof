<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\BareExceptionInstantiations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `src/` ne lève jamais de \LogicException ni de \RuntimeException nue
 * (issue #338) : les standards du projet veulent une exception explicite, qui
 * se cible dans `framework.exceptions` et se reconnaît dans les journaux. Les
 * dix-neuf qui restaient ont reçu une classe dédiée ; ce test empêche le
 * prochain Processor copié-collé d'en réintroduire une.
 *
 * Le recensement est éprouvé d'abord sur des extraits littéraux : un
 * recenseur qui ne trouverait jamais rien rendrait le second test vert sans
 * rien garder.
 */
final class NoBareGenericExceptionTest extends TestCase
{
    public function testNoSourceFileInstantiatesABareGenericException(): void
    {
        self::assertSame([], BareExceptionInstantiations::in(\dirname(__DIR__, 2).'/src'));
    }

    /**
     * @param list<int> $expectedLines
     */
    #[DataProvider('snippets')]
    public function testTheCensusFindsEveryFormOfTheForbiddenInstantiation(string $code, array $expectedLines): void
    {
        self::assertSame($expectedLines, BareExceptionInstantiations::inCode($code));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function snippets(): iterable
    {
        yield 'nom complet' => ["<?php\nnamespace App;\nthrow new \\LogicException('x');\n", [3]];
        yield 'RuntimeException' => ["<?php\nnamespace App;\nthrow new \\RuntimeException('x');\n", [3]];
        yield 'casse indifférente, comme PHP' => ["<?php\nnamespace App;\nthrow new \\logicexception('x');\n", [3]];
        yield 'importée' => ["<?php\nnamespace App;\nuse LogicException;\nthrow new LogicException('x');\n", [4]];
        yield 'importée sous un alias' => ["<?php\nnamespace App;\nuse RuntimeException as Boom;\nthrow new Boom('x');\n", [4]];
        yield 'dans une expression' => ["<?php\nnamespace App;\n\$a = \$b ?? throw new \\LogicException('x');\n", [3]];
        yield 'construite puis levée' => ["<?php\nnamespace App;\n\$e = new \\RuntimeException('x');\nthrow \$e;\n", [3]];
        yield 'importée dans une liste à virgules' => ["<?php\nnamespace App;\nuse Foo\\Bar, RuntimeException;\nthrow new RuntimeException('x');\n", [4]];
        yield 'liste à virgules avec alias' => ["<?php\nnamespace App;\nuse Foo\\Bar, LogicException as Boom;\nthrow new Boom('x');\n", [4]];
        yield '\\Exception nue, plus générique encore' => ["<?php\nnamespace App;\nthrow new \\Exception('x');\n", [3]];
        yield 'classe anonyme qui étend une exception interdite' => ["<?php\nnamespace App;\nthrow new class('x') extends \\LogicException {};\n", [3]];
        yield 'classe anonyme qui étend une exception dédiée' => ["<?php\nnamespace App;\nthrow new class('x') extends DedicatedException {};\n", []];
        yield 'fichier sans espace de noms' =>["<?php\nthrow new LogicException('x');\n", [2]];
        // Résolue dans l'espace de noms courant : App\LogicException, pas celle de PHP.
        yield 'homonyme de l\'espace de noms' => ["<?php\nnamespace App;\nthrow new LogicException('x');\n", []];
        yield 'exception dédiée' => ["<?php\nnamespace App;\nthrow UnsupportedOperationException::for(\$operation);\n", []];
        yield 'autre exception de la SPL, hors périmètre' => ["<?php\nnamespace App;\nthrow new \\InvalidArgumentException('x');\n", []];
        yield 'simple mention' => ["<?php\nnamespace App;\n\$class = \\LogicException::class;\n", []];
    }
}
