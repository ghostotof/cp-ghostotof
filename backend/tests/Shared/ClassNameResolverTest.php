<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ClassNameResolver;
use App\Tests\Support\InterpolatedStrings;
use App\Tests\Support\PhpSources;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La résolution partagée par NoBareGenericExceptionTest et
 * ProblemDetailStaysStaticTest (issue #507), prouvée pour elle-même : chacun
 * de ses deux utilisateurs n'en exerce qu'une partie.
 */
final class ClassNameResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        $header = "<?php\nnamespace App\\X;\nuse App\\Y\\Problem, App\\Z as Zed;\nuse Vendor\\Other as Alias;\n";

        yield 'nom complet' => [$header.'new \App\Q\Foo;', 'app\q\foo'];
        yield 'nom complet, casse ramenée' => [$header.'new \APP\q\FOO;', 'app\q\foo'];
        yield 'importé' => [$header.'new Problem;', 'app\y\problem'];
        yield 'importé, casse indifférente' => [$header.'new PROBLEM;', 'app\y\problem'];
        yield 'second d\'une liste à virgules, avec alias' => [$header.'new Zed\Foo;', 'app\z\foo'];
        yield 'importé par un import groupé' => ["<?php\nnamespace App\\X;\nuse App\\A\\{B, C as D};\nnew B;", 'app\a\b'];
        yield 'alias d\'un import groupé' => ["<?php\nnamespace App\\X;\nuse App\\A\\{B, C as D};\nnew D\\Foo;", 'app\a\c\foo'];
        yield 'alias' => [$header.'new Alias;', 'vendor\other'];
        yield 'relatif, dans l\'espace de noms courant' => [$header.'new Foo;', 'app\x\foo'];
        yield 'qualifié relatif' => [$header.'new Sub\Foo;', 'app\x\sub\foo'];
        yield 'namespace\\' => [$header.'new namespace\Foo;', 'app\x\foo'];
        yield 'NAMESPACE\\, casse indifférente' => [$header.'new NAMESPACE\Foo;', 'app\x\foo'];
        yield 'fichier sans espace de noms' => ["<?php\nnew Foo;", 'foo'];
        yield 'namespace\\ sans espace de noms' => ["<?php\nnew namespace\\Foo;", 'foo'];
        yield 'espace de noms global à accolades' => ["<?php\nnamespace {\nnew Foo;\n}", 'foo'];
    }

    /** Le nom qui suit le dernier `new` du code, résolu avec ses `namespace` et `use`. */
    #[DataProvider('names')]
    public function testResolvesLikePhp(string $code, string $expected): void
    {
        $tokens = PhpSources::significantTokens($code);
        $namespace = '';
        $imports = [];
        $resolved = null;
        foreach ($tokens as $index => $token) {
            if ($token->is(\T_NAMESPACE)) {
                $namespace = ClassNameResolver::namespaceDeclaredAt($tokens, $index);
            } elseif ($token->is(\T_USE)) {
                $imports = array_merge($imports, ClassNameResolver::imports($tokens, $index + 1));
            } elseif ($token->is(\T_NEW)) {
                $resolved = ClassNameResolver::resolve($tokens[$index + 1], $namespace, $imports);
            }
        }

        self::assertSame($expected, $resolved);
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function imports(): iterable
    {
        yield 'un nom' => ['use App\Y\Problem;', ['problem' => 'app\y\problem']];
        yield 'nom complet' => ['use \App\Y\Problem;', ['problem' => 'app\y\problem']];
        yield 'liste à virgules, alias compris' => ['use App\A, App\B as Bee, C;', ['a' => 'app\a', 'bee' => 'app\b', 'c' => 'c']];
        yield 'import groupé, alias compris' => [
            'use App\A\{B, C as D};',
            ['b' => 'app\a\b', 'd' => 'app\a\c'],
        ];
        yield 'import groupé, virgule finale' => ['use App\A\{B, C,};', ['b' => 'app\a\b', 'c' => 'app\a\c']];
        yield 'import groupé, nom complet' => ['use \App\A\{B};', ['b' => 'app\a\b']];
        yield 'import groupé, préfixe de deux segments' => ['use App\A\B\{C};', ['c' => 'app\a\b\c']];
        yield 'import groupé, membre qualifié' => ['use App\A\{B\C, D};', ['c' => 'app\a\b\c', 'd' => 'app\a\d']];
        yield 'import groupé mixte : seules les classes' => [
            'use App\A\{B, function f, const C, function g as h, D as E};',
            ['b' => 'app\a\b', 'e' => 'app\a\d'],
        ];
        yield 'use function groupé : aucune classe' => ['use function App\{f, g as h};', []];
        yield 'use const groupé : aucune classe' => ['use const App\{A, B};', []];
        yield 'use function : aucune classe' => ['use function App\f;', []];
        yield 'use const : aucune classe' => ['use const App\C;', []];
        yield 'use d\'une fermeture : aucune classe' => ['$f = function () use ($a) {};', []];
    }

    /** @param array<string, string> $expected */
    #[DataProvider('imports')]
    public function testReadsImports(string $statement, array $expected): void
    {
        $tokens = PhpSources::significantTokens("<?php\n".$statement);
        $use = array_key_first(array_filter($tokens, static fn (PhpToken $token): bool => $token->is(\T_USE)));
        self::assertIsInt($use);

        self::assertSame($expected, ClassNameResolver::imports($tokens, $use + 1));
    }

    /**
     * @return iterable<string, array{string, string}> le code, puis la classe que désigne le dernier nom écrit après `new`
     */
    public static function scoped(): iterable
    {
        yield 'import d\'un bloc namespace' => ["<?php\nnamespace X {\nuse A\\B;\nnew B;\n}", 'a\b'];
        yield 'import groupé' => ["<?php\nnamespace X;\nuse A\\{B, C as D};\nnew D;", 'a\c'];
        yield 'import gardé après le corps d\'une classe' => [
            "<?php\nnamespace X;\nuse A\\B;\nclass C { function f() {} }\nnew B;",
            'a\b',
        ];
        yield 'import après une chaîne interpolée' => ["<?php\nnamespace X;\n\$s = \"a {\$b} c\";\nuse A\\B;\nnew B;", 'a\b'];
        yield 'use d\'une fermeture : pas un import' => ["<?php\nnamespace X;\n\$f = function () use (\$a) { return new T; };", 'x\t'];

        // Un `use` dans un corps de classe importe un trait : le nom qu'il porte n'est pas un alias de classe.
        yield 'use de trait qualifié : pas un import' => [
            "<?php\nnamespace App\\X;\nfinal class A {\nuse Vendor\\T;\nfunction f() { return new T; }\n}",
            'app\x\t',
        ];
        yield 'use de trait court : pas un import' => [
            "<?php\nnamespace App\\X;\nfinal class A {\nuse T;\nfunction f() { return new T; }\n}",
            'app\x\t',
        ];
        yield 'use de trait et adaptation : pas un import' => [
            "<?php\nnamespace App\\X;\nfinal class A {\nuse Vendor\\T { foo as bar; }\nfunction f() { return new T; }\n}",
            'app\x\t',
        ];
        yield 'use de trait dans un bloc namespace : pas un import' => [
            "<?php\nnamespace X {\nclass A { use Vendor\\T; function f() { new T; } }\n}",
            'x\t',
        ];

        // Les imports d'un espace de noms ne valent pas dans le suivant, ni après la fin de son bloc.
        yield 'imports remis à zéro d\'un bloc au suivant' => [
            "<?php\nnamespace X { use A\\B; }\nnamespace Y { new B; }",
            'y\b',
        ];
        // PHP refuse du code hors d'un bloc quand le fichier en compte, mais le recenseur lit des jetons : il ne garde rien.
        yield 'imports remis à zéro à la fin du bloc' => ["<?php\nnamespace X { use A\\B; }\nnew B;", 'b'];

        // Un fragment de chaîne interpolée dont le texte est `}` ou `{` n'est pas une accolade du code : l'import
        // qui suit reste au premier niveau (C15-2 : lu par le texte, le recenseur résout `C` vers `app\c`).
        foreach (InterpolatedStrings::BRACES as $label => $string) {
            yield 'import après '.$label => [
                "<?php\nnamespace App;\n\$j = ".$string.";\nuse Symfony\\Component\\HttpFoundation\\Cookie as C;\nnew C('x');",
                'symfony\component\httpfoundation\cookie',
            ];
            yield 'fin de bloc namespace après '.$label => [
                "<?php\nnamespace App { function f() { return ".$string."; }\nuse A\\B; }\nnew B;",
                'b',
            ];
        }
    }

    #[DataProvider('scoped')]
    public function testScopesCarryWhatHoldsAtEachToken(string $code, string $expected): void
    {
        $tokens = PhpSources::significantTokens($code);
        $resolved = null;
        foreach (ClassNameResolver::scopes($tokens) as $index => [$namespace, $imports]) {
            $before = $tokens[$index - 1] ?? null;
            if ($tokens[$index]->is(ClassNameResolver::NAMES) && null !== $before && $before->is(\T_NEW)) {
                $resolved = ClassNameResolver::resolve($tokens[$index], $namespace, $imports);
            }
        }

        self::assertSame($expected, $resolved);
    }
}
