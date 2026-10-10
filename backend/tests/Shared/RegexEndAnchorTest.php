<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\DollarAnchoredPatterns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Composite;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Aucun motif de `src/` ne s'ancre par `$` (issue #409, suite de #386) : en
 * PCRE, `$` accepte aussi la position qui précède un `\n` final, et un motif
 * de validation `/^[a-z]+$/` laisse passer `"abc\n"`. Le remède est `\z`.
 *
 * `\z` plutôt que le modificateur `D`, qui aurait le même effet : l'ancre se
 * lit là où elle agit, au lieu de dépendre d'une lettre en fin de motif
 * qu'une copie du corps seul (dans un message, un test, le frontend) perd en
 * route ; c'est aussi la forme que #386 a retenue pour
 * `CpgUser::USERNAME_PATTERN`, un seul idiome dans le dépôt.
 *
 * Le recensement est d'abord éprouvé sur des extraits littéraux : un
 * recenseur qui ne trouverait jamais rien rendrait le premier test vert sans
 * rien garder.
 */
final class RegexEndAnchorTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    public function testNoSourcePatternIsAnchoredByADollar(): void
    {
        self::assertSame(
            [],
            DollarAnchoredPatterns::in(self::SOURCES),
            'Ancrez la fin par \z : `$` accepte un "\n" final (issue #409).',
        );
    }

    /**
     * @param list<int> $expectedLines
     */
    #[DataProvider('snippets')]
    public function testTheCensusFindsEveryDollarAnchor(string $code, array $expectedLines): void
    {
        self::assertSame($expectedLines, array_column(DollarAnchoredPatterns::inCode($code), 0));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function snippets(): iterable
    {
        yield 'constante' => ["<?php\nconst P = '/^[a-z]+$/';\n", [2]];
        yield 'attribut Assert\Regex' => ["<?php\nclass A {\n#[Assert\\Regex(pattern: '/^[a-z]+$/')]\npublic string \$a;\n}\n", [3]];
        yield 'appel en ligne' => ["<?php\npreg_match('/^\\d+$/', \$a);\n", [2]];
        yield 'avec modificateurs' => ["<?php\nconst P = '/^[a-z]+$/iu';\n", [2]];
        yield 'autre délimiteur' => ["<?php\nconst P = '#^[a-z]+$#';\n", [2]];
        yield 'délimiteurs appariés' => ["<?php\nconst P = '{^[a-z]+$}';\n", [2]];
        yield 'au milieu d\'une alternative' => ["<?php\nconst P = '/^(?:a|$)/';\n", [2]];
        yield 'avant une barre verticale' => ["<?php\nconst P = '/a$|b/';\n", [2]];
        yield 'guillemets doubles, `\$` résolu par PHP' => ["<?php\nconst P = \"/^a\\\$/\";\n", [2]];
        // `\x24`, `\044` et `\u{24}` sont trois écritures de `$` entre guillemets doubles.
        yield 'guillemets doubles, dollar en hexadécimal' => ["<?php\nconst P = \"/^a\\x24/\";\n", [2]];
        yield 'guillemets doubles, dollar en octal' => ["<?php\nconst P = \"/^a\\044/\";\n", [2]];
        yield 'guillemets doubles, dollar en point de code' => ["<?php\nconst P = \"/^a\\u{24}/\";\n", [2]];
        yield 'barre oblique inverse échappée, puis l\'ancre' => ["<?php\nconst P = '/^a\\\\\\\\$/';\n", [2]];
        yield 'préfixe binaire' => ["<?php\nconst P = b'/^a$/';\n", [2]];
        yield 'dans un tableau' => ["<?php\nconst P = ['x' => '/^a$/'];\n", [2]];
        // Ce qui n'est pas un `$` ancre, ou ce que le motif rend légitime.
        yield 'ancré par \z' => ["<?php\nconst P = '/^[a-z]+\\z/';\n", []];
        yield 'modificateur D' => ["<?php\nconst P = '/^[a-z]+$/D';\n", []];
        yield 'modificateur m, fin de ligne voulue' => ["<?php\nconst P = '/^a=(\\S+)$/m';\n", []];
        yield 'dollar échappé' => ["<?php\nconst P = '/^\\\$\\d+\\z/';\n", []];
        yield 'dollar en classe de caractères' => ["<?php\nconst P = '/^[#\$%]+\\z/';\n", []];
        yield 'crochet fermant en tête de classe' => ["<?php\nconst P = '/^[]\$]+\\z/';\n", []];
        yield 'classe niée, crochet fermant en tête' => ["<?php\nconst P = '/^[^]\$]+\\z/';\n", []];
        yield 'classe POSIX dans une classe' => ["<?php\nconst P = '/^[[:alpha:]\$]+\\z/';\n", []];
        yield 'citation \Q…\E' => ["<?php\nconst P = '/^\\Q\$\\E\\z/';\n", []];
        yield 'chemin, pas un motif' => ["<?php\nconst P = '/api/account/base-access';\n", []];
        yield 'gabarit de remplacement' => ["<?php\nconst P = '\${1}***';\n", []];
        yield 'commentaire' => ["<?php\n// preg_match('/^a$/', \$a);\n", []];
        // Le code lu est `"/^a\\\$/"` : PHP en fait `/^a\$/`, un dollar littéral.
        yield 'guillemets doubles, dollar échappé pour PCRE' => ["<?php\nconst P = \"/^a\\\\\\\$/\";\n", []];
    }

    /**
     * Le pendant de `\z` côté OpenAPI. API Platform publie le motif d'un
     * `#[Assert\Regex]` dans le schéma à partir de `getHtmlPattern()`, qui ne
     * sait retirer qu'un `$` final : un `\z` y resterait, et ECMAScript, le
     * dialecte des `pattern` JSON Schema, le lit comme un « z » littéral. Le
     * schéma publié exigerait alors un « z » dans chaque slug. Tout
     * `Assert\Regex` ancré par `\z` déclare donc son `htmlPattern`.
     */
    public function testEveryRegexConstraintPublishesAPatternThatEcmaScriptReads(): void
    {
        $found = [];
        foreach (DeclaredClasses::all(self::SOURCES) as $class) {
            foreach ($this->regexConstraintsOf(new ReflectionClass($class)) as $where => $constraint) {
                $htmlPattern = $constraint->getHtmlPattern();
                if (null !== $htmlPattern && 1 === preg_match('/\\\\[AzZ]/', $htmlPattern)) {
                    $found[] = $where.' '.$htmlPattern;
                }
            }
        }

        self::assertSame([], $found, 'Déclarez un htmlPattern sans \A, \z ni \Z (ECMAScript ne les connaît pas).');
    }

    /**
     * Les `Regex` déclarées en attribut sur la classe, ses propriétés (les
     * paramètres promus en font partie) et ses méthodes, imbriquées comprises
     * (`All`, `Sequentially`…).
     *
     * @param ReflectionClass<object> $class
     *
     * @return iterable<string, Regex>
     */
    private function regexConstraintsOf(ReflectionClass $class): iterable
    {
        $targets = ['' => $class];
        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class->getName()) {
                $targets['::$'.$property->getName()] = $property;
            }
        }
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class->getName()) {
                $targets['::'.$method->getName().'()'] = $method;
            }
        }

        foreach ($targets as $suffix => $target) {
            foreach ($target->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $index => $attribute) {
                foreach (self::flatten($attribute->newInstance()) as $nested => $constraint) {
                    if ($constraint instanceof Regex) {
                        yield $class->getName().$suffix.'#'.$index.'.'.$nested => $constraint;
                    }
                }
            }
        }
    }

    /**
     * @return list<Constraint> la contrainte et toutes celles qu'elle imbrique
     */
    private static function flatten(Constraint $constraint): array
    {
        $all = [$constraint];
        if ($constraint instanceof Composite) {
            foreach ($constraint->getNestedConstraints() as $nested) {
                array_push($all, ...self::flatten($nested));
            }
        }

        return $all;
    }
}
