<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\MemberCalls;
use App\Tests\Support\PhpSources;
use Closure;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La forme d'un appel de membre, prouvée pour elle-même (#748, R161) : quatre détecteurs par jetons la lisent
 * (`PathIsDecidedCanonicallyTest`, `AuthorizationSites`, `CookieConstructions`, `PlainPasswordInputs`), chacun
 * ne prouve que les noms qu'il cherche. Ici, tout nom d'un code est jugé ; le spec rend les noms que le prédicat retient.
 */
final class MemberCallsTest extends TestCase
{
    /**
     * @param list<string> $any      les noms d'appels de membre, de toute écriture
     * @param list<string> $instance ceux d'un appel d'objet (`->`, `?->`)
     * @param list<string> $static   ceux d'un appel statique (`::`)
     */
    #[DataProvider('codes')]
    public function testEachPredicateKeepsTheNamesItsFormCalls(string $code, array $any, array $instance, array $static): void
    {
        self::assertSame($any, $this->namesCalledBy($code, MemberCalls::isCallAt(...)), 'isCallAt');
        self::assertSame($instance, $this->namesCalledBy($code, MemberCalls::isInstanceCallAt(...)), 'isInstanceCallAt');
        self::assertSame($static, $this->namesCalledBy($code, MemberCalls::isStaticCallAt(...)), 'isStaticCallAt');
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>, list<string>}>
     */
    public static function codes(): iterable
    {
        yield 'appel d\'objet' => ["<?php\n\$a->run();\n", ['run'], ['run'], []];
        yield 'appel d\'objet nullsafe' => ["<?php\n\$a?->run();\n", ['run'], ['run'], []];
        yield 'appel statique' => ["<?php\nA::run();\n", ['run'], [], ['run']];
        yield 'appelable de première classe' => ["<?php\n\$f = \$a->run(...);\n", ['run'], ['run'], []];
        yield 'appel sur plusieurs lignes' => ["<?php\n\$a\n    ->run\n    ();\n", ['run'], ['run'], []];
        yield 'appels enchaînés' => [
            "<?php\nA::first()->second()?->third();\n",
            ['first', 'second', 'third'],
            ['second', 'third'],
            ['first'],
        ];
        yield 'seul le dernier maillon d\'une propriété enchaînée' => ["<?php\n\$a->prop->run();\n", ['run'], ['run'], []];
        yield 'propriété sans parenthèse' => ["<?php\n\$x = \$a->run;\n", [], [], []];
        yield 'constante de classe' => ["<?php\n\$x = A::RUN;\n", [], [], []];
        yield 'nom de classe' => ["<?php\n\$x = A::class;\n", [], [], []];
        yield 'nom sans appel, suivi d\'une autre instruction' => ["<?php\n\$a->run;\n(\$b);\n", [], [], []];
        yield 'nom dynamique' => ["<?php\n\$a->\$name();\nA::\$name();\n", [], [], []];
        yield 'fonction libre' => ["<?php\nrun();\n\\run();\n", [], [], []];
        yield 'déclaration d\'une méthode' => ["<?php\nclass A { public function run(): void {} }\n", [], [], []];
        yield 'instanciation' => ["<?php\n\$a = new Run();\n", [], [], []];
        yield 'commentaire et chaîne' => ["<?php\n// \$a->run()\n\$s = '\$a->run()';\n", [], [], []];
    }

    public function testAnIndexOutsideTheTokensIsNoCall(): void
    {
        $tokens = PhpSources::significantTokens("<?php\n\$a->run();\n");

        self::assertFalse(MemberCalls::isCallAt($tokens, 0));
        self::assertFalse(MemberCalls::isCallAt($tokens, \count($tokens) + 5));
        self::assertFalse(MemberCalls::isInstanceCallAt($tokens, -1));
    }

    /**
     * @param Closure(list<PhpToken>, int): bool $predicate
     *
     * @return list<string>
     */
    private function namesCalledBy(string $code, Closure $predicate): array
    {
        $tokens = PhpSources::significantTokens($code);
        $names = [];
        foreach ($tokens as $index => $token) {
            if ($predicate($tokens, $index)) {
                $names[] = $token->text;
            }
        }

        return $names;
    }
}
