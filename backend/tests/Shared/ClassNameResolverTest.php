<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ClassNameResolver;
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
}
