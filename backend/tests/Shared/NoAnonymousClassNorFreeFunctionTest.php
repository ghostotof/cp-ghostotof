<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\AnonymousClassesAndFreeFunctions;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `src/` ne déclare ni classe anonyme ni fonction hors classe (issue #432,
 * suite de #421).
 *
 * Les gardes de signatures ({@see \App\Tests\Security\SecretParametersTest},
 * {@see \App\Tests\Security\User\PlainPasswordInputsTest}) et
 * {@see \App\Tests\Shared\RegexEndAnchorTest} ne lisent que ce que recense
 * {@see \App\Tests\Support\DeclaredClasses} : les déclarations nommées. Une
 * classe anonyme ou une fonction hors classe leur échappe en silence, la
 * réflexion ne les atteignant pas sans exécuter le code. Ce test fait de
 * cette limite une contrainte de `src/` : le jour où l'une apparaît, il
 * échoue au lieu de laisser les gardes vertes sans rien garder.
 *
 * Les closures et les fonctions fléchées restent permises : leur paramètre
 * reçoit une valeur sous le nom générique de son contrat, qu'un recensement
 * par nom ne servirait pas (raisons dans la docblock de SecretParametersTest).
 *
 * Le recensement est d'abord éprouvé sur des extraits littéraux : un
 * recenseur qui ne trouverait jamais rien rendrait le premier test vert sans
 * rien garder.
 */
final class NoAnonymousClassNorFreeFunctionTest extends TestCase
{
    public function testNoSourceFileDeclaresAnAnonymousClassOrAFreeFunction(): void
    {
        self::assertSame(
            [],
            AnonymousClassesAndFreeFunctions::in(RepositoryFiles::backend('src')),
            'Nommez la classe ou faites de la fonction une méthode : les gardes de signatures ne voient que les'
            .' déclarations nommées (issue #432, .claude/rules/backend-architecture.md).',
        );
    }

    /**
     * @param list<string> $expected `ligne: nature` de chaque déclaration recensée
     */
    #[DataProvider('snippets')]
    public function testTheCensusFindsEveryAnonymousClassAndFreeFunction(string $code, array $expected): void
    {
        self::assertSame($expected, AnonymousClassesAndFreeFunctions::inCode($code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function snippets(): iterable
    {
        // Ce que la garde refuse.
        yield 'classe anonyme' => ["<?php\nnamespace App;\n\$a = new class {};\n", ['3: classe anonyme']];
        yield 'classe anonyme avec arguments et parent' => ["<?php\nnamespace App;\nreturn new class(\$d) extends Base implements I {};\n", ['3: classe anonyme']];
        yield 'classe anonyme en lecture seule' => ["<?php\nnamespace App;\n\$a = new readonly class {};\n", ['3: classe anonyme']];
        yield 'classe anonyme sous attribut' => ["<?php\nnamespace App;\n\$a = new #[Attr] class {};\n", ['3: classe anonyme']];
        yield 'fonction hors classe' => ["<?php\nnamespace App;\nfunction helper(): void {}\n", ['3: fonction helper()']];
        yield 'fonction hors classe par référence' => ["<?php\nnamespace App;\nfunction &helper(): array {}\n", ['3: fonction helper()']];
        yield 'fichier sans espace de noms' => ["<?php\nfunction helper(): void {}\n", ['2: fonction helper()']];
        // Déclarée à l'exécution de la méthode, elle n'en devient pas une : elle rejoint l'espace global.
        yield 'fonction déclarée dans une méthode' => ["<?php\nnamespace App;\nfinal class A {\n    public function m(): void {\n        function inner(): void {}\n    }\n}\n", ['5: fonction inner()']];
        yield 'fonction déclarée dans une closure' => ["<?php\nnamespace App;\n\$f = function (): void {\n    function inner(): void {}\n};\n", ['4: fonction inner()']];
        yield 'méthode d\'une classe anonyme : seule la classe compte' => ["<?php\nnamespace App;\n\$a = new class {\n    public function m(): void {}\n};\n", ['3: classe anonyme']];
        // Une closure en argument ouvre une accolade avant le corps de la classe anonyme.
        yield 'classe anonyme qui reçoit une closure' => ["<?php\nnamespace App;\n\$a = new class(function () {}) {\n    public function m(): void {}\n};\n", ['3: classe anonyme']];
        // Le corps attendu de la classe externe survit à la classe déclarée dans ses arguments.
        yield 'classe anonyme dans les arguments d\'une classe anonyme' => ["<?php\nnamespace App;\n\$a = new class(function () { return new class {}; }) {\n    public function m(): void {}\n};\n", ['3: classe anonyme', '3: classe anonyme']];
        yield 'fonction au nom de mot-clé' => ["<?php\nnamespace App;\nfunction readonly(): void {}\n", ['3: fonction readonly()']];
        yield 'fonction déclarée sous condition' => ["<?php\nnamespace App;\nif (\$a) { function helper(): void {} }\n", ['3: fonction helper()']];
        // L'adaptation de trait se ferme par `}`, sans `;` : l'instruction `use` s'arrête là.
        yield 'fonction après une adaptation de trait vide' => ["<?php\nnamespace App;\nfinal class A { use T {} }\nfunction helper(): void {}\n", ['4: fonction helper()']];
        // Un argument nommé `class:` n'attend aucun corps : la closure qui suit n'en est pas un.
        yield 'fonction dans une closure après un argument nommé class' => ["<?php\nnamespace App;\nf(class: 1, g: function (): void {\n    function inner(): void {}\n});\n", ['4: fonction inner()']];
        yield 'les deux dans un fichier' => ["<?php\nnamespace App;\nfunction helper(): void {}\n\$a = new class {};\n", ['3: fonction helper()', '4: classe anonyme']];

        // Ce que la garde laisse passer.
        yield 'méthode d\'une classe' => ["<?php\nnamespace App;\nfinal class A {\n    public function m(): void {}\n}\n", []];
        yield 'méthode abstraite d\'une interface' => ["<?php\nnamespace App;\ninterface I {\n    public function m(): void;\n}\n", []];
        yield 'méthode d\'un trait' => ["<?php\nnamespace App;\ntrait T {\n    public function m(): void {}\n}\n", []];
        yield 'méthode d\'un enum' => ["<?php\nnamespace App;\nenum E: string implements I {\n    case A = 'a';\n    public function m(): void {}\n}\n", []];
        yield 'closure' => ["<?php\nnamespace App;\n\$f = function (\$value) use (\$a) { return \$value; };\n", []];
        yield 'closure statique par référence' => ["<?php\nnamespace App;\n\$f = static function &(\$value) { return \$value; };\n", []];
        yield 'fonction fléchée' => ["<?php\nnamespace App;\n\$f = fn (\$value) => \$value;\n", []];
        yield 'nom de classe lu par ::class' => ["<?php\nnamespace App;\n\$a = Foo::class;\n\$b = \$o::class;\n", []];
        yield 'import de fonction' => ["<?php\nnamespace App;\nuse function strlen;\n", []];
        yield 'arguments nommés class et function' => ["<?php\nnamespace App;\nf(class: 1, function: 2, interface: 3);\n", []];
        yield 'import groupé de fonctions' => ["<?php\nnamespace App;\nuse Foo\\{Bar, function baz, function qux};\n", []];
        // Une accolade d'interpolation se referme comme une autre : la méthode suivante reste une méthode.
        yield 'accolade d\'interpolation dans une méthode' => ["<?php\nnamespace App;\nfinal class A {\n    public function m(): string { return \"{\$this->a}\"; }\n    public function n(): void {}\n}\n", []];
    }
}
