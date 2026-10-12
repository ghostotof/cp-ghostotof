<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
use App\Portfolio\Shared\Domain\Exception\TranslationAlreadyExistsException;
use App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException;
use App\Portfolio\Shared\Domain\Exception\UnknownTranslationGroupException;
use App\Tests\Support\ClassNameResolver;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\PhpSources;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ApiProblemResponseListener renvoie au client le `detail` (le message) de
 * toute ProblemExceptionInterface levée sous /api hors API Platform (issue
 * #322), et API Platform fait de même sur ses propres routes. Un message
 * construit avec ce que le client a envoyé (une borne D6 qui citerait le
 * message trop long, par exemple) le renverrait tel quel. Garde-fou statique,
 * sur toutes les ProblemExceptionInterface de src/ :
 *
 *  - son getDetail(), s'il en a un, rend son message et rien d'autre
 *    (`return $this->getMessage();`, celui de HasProblemType) ;
 *  - elle n'affecte jamais `$this->message` (`=`, `.=`, `??=`), ce qui
 *    contournerait le constructeur ;
 *  - une classe qui a un constructeur passe un message littéral à chacun de
 *    ses `parent::__construct` — elle fixe alors son message, et ses sites de
 *    construction importent peu (une cause en `previous`, par exemple) ;
 *  - pour les autres, chaque `new` (y compris `new self`/`new static`, alias
 *    `use … as` résolus, dans n'importe quel contexte syntaxique) ne reçoit
 *    comme message qu'une chaîne littérale, un heredoc sans variable ou rien.
 *
 * Les méthodes jugées sont celles que PHP retient (issue #514) : celles de la
 * classe, puis celles de ses traits (directement ou par un autre trait,
 * `insteadof` et alias `as` lus), puis celles de son parent, résolu de même
 * — un parent qui n'est pas lui-même une ProblemExceptionInterface compris ;
 * une méthode abstraite ne compte pas. Un `new self` construit la classe où
 * il s'écrit, ou chaque classe qui utilise le trait où il s'écrit ; un
 * `new static`, aussi chaque sous-classe ; un `new parent`, le parent. Une
 * affectation de `$this->message` compte où qu'elle s'écrive dans cette
 * lignée. Les noms se résolvent comme PHP le fait — casse indifférente,
 * listes `use A, B;`, `namespace\X`, imports propres à chaque espace de noms
 * — par {@see ClassNameResolver}, partagé avec NoBareGenericExceptionTest
 * (issue #507).
 *
 * Par jetons plutôt que par réflexion (choix de #514) : la réflexion donne
 * la méthode que PHP retient toute faite, mais ne lit que des classes
 * chargées — chaque extrait de testTheDetector, écrit comme une chaîne,
 * deviendrait une classe de fixture, et deux déclarations du même nom n'en
 * chargeraient qu'une. La réflexion sert donc de témoin :
 * testTheTokenModelResolvesMethodsAsPhpDoes vérifie que, sur src/, le
 * modèle par jetons retient le même getDetail() et le même constructeur
 * qu'elle.
 *
 * Une exception au message dynamique légitime ailleurs (rendue par API
 * Platform sur une autre route) s'inscrit dans DYNAMIC_DETAIL_ALLOWED, avec sa
 * justification : c'est la seule façon d'échapper au garde-fou, et elle se voit.
 *
 * Analyse par jetons (PhpToken), pas par expression régulière : commentaires,
 * `;` dans une chaîne et arguments nommés ne le trompent pas. Les fichiers lus
 * et les jetons écartés (espaces, commentaires) sont ceux de {@see PhpSources},
 * comme pour les autres recenseurs (issue #395) : une évolution de l'un atteint
 * ce garde-fou aussi. Ce qu'il ne voit pas, et c'est assumé — il vise l'oubli,
 * pas la malveillance :
 *  - une classe instanciée dynamiquement (`new $classe`, Reflection) ;
 *  - un parent ou un trait hors des sources lues (vendor, classe native) :
 *    il n'est pas lu, et la classe garde ce qu'elle déclare elle-même ;
 *  - une collision que PHP refuse (deux traits sans `insteadof`, un alias
 *    qui en heurte un autre) : le premier trait nommé, ou l'alias,
 *    l'emporte — la classe ne se chargerait pas ;
 *  - `$this->message` modifié autrement que par affectation directe
 *    (référence, Reflection, `Closure::bind`) ;
 *  - l'import groupé (`use Foo\{A, B};`) : ImportsStaySortedTest le refuse
 *    déjà dans src/ et tests/ ;
 *  - une classe déclarée dans le corps d'une méthode ou d'une fonction : les
 *    fonctions hors classe sont interdites dans src/ (issue #432), et une
 *    déclaration conditionnelle n'y existe pas.
 *
 * Un Construction est un `new` : sa sorte (BY_NAME ou RELATIVE_NAMES), la
 * classe nommée ou la déclaration où il s'écrit, son message littéral ou
 * non, sa ligne.
 *
 * @phpstan-type Method array{path: string, line: int, returnsMessage: bool, messageIsLiteral: bool}
 * @phpstan-type Declaration array{
 *     name: string,
 *     parent: ?string,
 *     traits: list<string>,
 *     insteadof: array<string, string>,
 *     aliases: list<array{?string, string, string}>,
 *     methods: array<string, Method>,
 *     assignsMessage: bool,
 * }
 * @phpstan-type Construction array{string, string, bool, int}
 */
final class ProblemDetailStaysStaticTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../src';

    /**
     * Messages dynamiques admis, parce qu'aucune de ces exceptions ne peut être
     * levée sous /api/assistant : elles appartiennent à des routes de
     * backoffice (ROLE_SUPER), rendues par API Platform, et citent une donnée
     * que leur appelant connaît déjà.
     */
    private const array DYNAMIC_DETAIL_ALLOWED = [
        TranslationAlreadyExistsException::class => 'Écritures du backoffice : cite le groupe et la locale envoyés.',
        UnknownTranslationGroupException::class => 'Écritures du backoffice : cite le groupe envoyé.',
        IncompleteOrderException::class => 'PUT /api/backoffice/<x>/order : cite les clés manquantes.',
        UnknownOrderEntryException::class => 'PUT /api/backoffice/<x>/order : cite la clé inconnue.',
    ];

    private const array NAME_TOKENS = [...ClassNameResolver::NAMES, \T_STATIC];

    /** Les noms qu'un `new` écrit relativement à sa déclaration ; tout autre est {@see self::BY_NAME}. */
    private const array RELATIVE_NAMES = ['self', 'static', 'parent'];
    private const string BY_NAME = 'name';

    /** Ce qui, écrit après `$this->message`, lui affecte une valeur. */
    private const array ASSIGNMENTS = ['=', \T_CONCAT_EQUAL, \T_COALESCE_EQUAL];
    private const array LITERAL_HEREDOC_TOKENS = [\T_START_HEREDOC, \T_ENCAPSED_AND_WHITESPACE, \T_END_HEREDOC];

    /** Le corps de `getDetail()` qui rend le message, jetons en minuscules après l'accolade ouvrante. */
    private const array RETURNS_THE_MESSAGE = ['return', '$this', '->', 'getmessage', '(', ')', ';', '}'];

    public function testEveryProblemDetailInSourcesIsALiteral(): void
    {
        $sources = $this->sourceFiles();
        $problems = DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class);
        self::assertNotEmpty($problems, 'Aucune ProblemExceptionInterface trouvée : le garde-fou ne garderait rien.');
        self::assertSame([], array_diff(array_keys(self::DYNAMIC_DETAIL_ALLOWED), $problems), 'Entrée admise qui n\'existe plus : la retirer.');
        $problems = array_values(array_diff($problems, array_keys(self::DYNAMIC_DETAIL_ALLOWED)));

        self::assertSame([], $this->violations($sources, $problems));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function snippets(): iterable
    {
        $header = "<?php\nnamespace App\\X;\nuse App\\Y\\Problem;\nuse App\\Y\\Problem as Alias;\n";

        yield 'littéral' => [$header."throw new Problem('Vide.');", 0];
        yield 'sans argument' => [$header.'throw new Problem();', 0];
        yield 'argument nommé littéral' => [$header."throw new Problem(message: 'Vide.');", 0];
        yield 'code et cause après un littéral' => [$header."throw new Problem('Vide.', 0, previous: \$e);", 0];
        yield 'nowdoc' => [$header."throw new Problem(<<<'TXT'\n    Vide.\n    TXT);", 0];
        yield 'point-virgule dans le littéral' => [$header."throw new Problem('a; b');", 0];
        yield 'commentaire' => [$header."// throw new Problem('x'.\$c);\nthrow new Problem('Vide.');", 0];

        yield 'concaténation' => [$header."throw new Problem('Trop long : '.\$c);", 1];
        yield 'sprintf' => [$header."throw new Problem(sprintf('%s', \$c));", 1];
        yield 'variable' => [$header.'throw new Problem($message);', 1];
        yield 'nommé dynamique' => [$header.'throw new Problem(message: $c);', 1];
        yield 'heredoc interpolé' => [$header."throw new Problem(<<<TXT\n    Trop long : \$c\n    TXT);", 1];
        yield 'point-virgule puis concaténation' => [$header."throw new Problem('a; b'.\$c);", 1];
        yield 'alias' => [$header."throw new Alias('x'.\$c);", 1];
        yield 'nom complet' => [$header."throw new \\App\\Y\\Problem('x'.\$c);", 1];
        yield 'dans un tableau' => [$header."\$l = [new Problem('x'.\$c)];", 1];
        yield 'dans une fermeture' => [$header."\$f = fn () => new Problem('x'.\$c);", 1];
        yield 'autre classe ignorée' => [$header."throw new \\RuntimeException('x'.\$c);", 0];

        // Résolution des noms (issue #507) : listes à virgules, casse, `namespace\`.
        yield 'importée dans une liste à virgules' => ["<?php\nnamespace App\\X;\nuse App\\X\\Foo, App\\Y\\Problem;\nthrow new Problem('x'.\$c);", 1];
        yield 'liste à virgules avec alias' => ["<?php\nnamespace App\\X;\nuse App\\X\\Foo, App\\Y\\Problem as Boom;\nthrow new Boom('x'.\$c);", 1];
        yield 'nom complet en casse différente' => [$header."throw new \\App\\Y\\problem('x'.\$c);", 1];
        yield 'importée en casse différente' => ["<?php\nnamespace App\\X;\nuse app\\y\\PROBLEM;\nthrow new problem('x'.\$c);", 1];
        yield 'nom relatif à l\'espace de noms' => ["<?php\nnamespace App\\Y;\nthrow new namespace\\Problem('x'.\$c);", 1];
        yield 'namespace\\ ailleurs' => [$header."throw new namespace\\Problem('x'.\$c);", 0];
        yield 'imports remis à zéro au namespace suivant' => [$header."namespace App\\Q;\nthrow new Problem('x'.\$c);", 0];
        yield 'espace de noms à accolades' => ["<?php\nnamespace App\\X {\nuse App\\Y\\Problem;\nthrow new Problem('x'.\$c);\n}", 1];

        $class = "<?php\nnamespace App\\Y;\nfinal class Problem extends \\DomainException {\n";
        yield 'fabrique new self' => [$class."    public static function tooLong(string \$c): self { return new self('Trop long : '.\$c); }\n}", 1];
        yield 'fabrique new static' => [$class."    public static function tooLong(string \$c): static { return new static(\$c); }\n}", 1];
        yield 'getDetail surchargé' => [$class."    public function getDetail(): string { return \$this->c; }\n}", 1];
        yield 'constructeur qui formate' => [$class."    public function __construct(string \$c) { parent::__construct('Trop long : '.\$c); }\n}", 1];
        yield 'constructeur au message fixe' => [$class."    public function __construct(?\\Throwable \$p = null) { parent::__construct('Vide.', 0, \$p); }\n}\n"
            ."function f(\\Throwable \$e) { throw new Problem(\$e); }", 0];
        yield 'second parent::__construct composé' => [$class."    public function __construct(string \$c) { if ('' === \$c) { parent::__construct('Vide.'); } else { parent::__construct('Trop long : '.\$c); } }\n}", 1];
        yield 'getDetail par référence' => [$class."    public function &getDetail(): ?string { return \$this->c; }\n}", 1];
        yield 'affecte $this->message' => [$class."    public function __construct(string \$c) { \$this->message = \$c; }\n}", 1];
        yield 'affecte $this->message après le parent' => [$class."    public function __construct(string \$c) { parent::__construct('x'); \$this->message .= \$c; }\n}", 1];
        yield 'lit $this->message' => [$class."    public function f(): string { return \$this->message; }\n}", 0];
        yield 'constructeur qui interpole ${x}' => [$class."    public function __construct(string \$c) { \$m = \"a \${c}\"; parent::__construct(\$m); }\n}", 1];

        // Fin de la classe (issue #507) : ce qui suit ne lui appartient plus.
        yield 'fonction après la classe' => [$class."}\nfunction getDetail(): string { return ''; }", 0];
        yield 'new self après la classe' => [$class."}\nfunction f(string \$c): void { new self('x'.\$c); }", 0];
        yield 'interface après la classe' => [$class."}\ninterface Other { public function getDetail(): string; }", 0];
        // `class:` et `trait:` en argument nommé sont lexés comme mots-clés : ils n'ouvrent rien.
        foreach (['class', 'trait'] as $keyword) {
            yield 'argument nommé '.$keyword.':' => [$class."    public function f(): void { g({$keyword}: 1); }\n    public function getDetail(): string { return \$this->c; }\n}", 1];
        }

        // Traits (issue #507) : leurs méthodes sont celles de la classe qui les utilise.
        $namespace = "<?php\nnamespace App\\Y;\n";
        $user = "final class Problem extends \\DomainException {\n    use Formats;\n}\n";
        yield 'constructeur qui formate, par un trait' => [$namespace."trait Formats {\n    public function __construct(string \$c) { parent::__construct('Trop long : '.\$c); }\n}\n".$user, 1];
        yield 'getDetail surchargé, par un trait' => [$namespace."trait Formats {\n    public function getDetail(): string { return \$this->c; }\n}\n".$user, 1];
        yield 'getDetail surchargé, par le trait d\'un trait' => [$namespace."trait Details {\n    public function getDetail(): string { return \$this->c; }\n}\ntrait Formats {\n    use Details;\n}\n".$user, 1];
        yield 'trait au message fixe' => [$namespace."trait Formats {\n    public function __construct(?\\Throwable \$p = null) { parent::__construct('Vide.', 0, \$p); }\n}\n".$user
            ."final class Thrower { public function f(\\Throwable \$e): never { throw new Problem(\$e); } }", 0];
        yield 'constructeur de la classe, prioritaire sur celui du trait' => [$namespace."trait Formats {\n    public function __construct(string \$c) { parent::__construct('Trop long : '.\$c); }\n}\n"
            ."final class Problem extends \\DomainException {\n    use Formats;\n    public function __construct() { parent::__construct('Vide.'); }\n}\n", 0];

        // getDetail() qui rend le message : celui de HasProblemType, que toutes
        // les ProblemExceptionInterface de src/ utilisent (issue #507).
        $message = "    public function getDetail(): ?string { return \$this->getMessage(); }\n";
        yield 'getDetail qui rend le message' => [$class.$message.'}', 0];
        yield 'getDetail qui rend le message, par un trait' => [$namespace."trait Formats {\n".$message."}\n".$user, 0];
        yield 'getDetail abstrait d\'un trait' => [$namespace."trait Formats {\n    abstract public function getDetail(): ?string;\n}\n".$user, 0];
        yield 'getDetail de la classe, prioritaire sur celui du trait' => [$namespace."trait Formats {\n".$message."}\n"
            ."final class Problem extends \\DomainException {\n    use Formats;\n    public function getDetail(): string { return \$this->c; }\n}\n", 1];
        yield 'getDetail du trait, remplacé par celui de la classe' => [$namespace."trait Formats {\n    public function getDetail(): string { return \$this->c; }\n}\n"
            ."final class Problem extends \\DomainException {\n    use Formats;\n".$message."}\n", 0];
        yield 'constructeur abstrait' => [$namespace."abstract class Problem extends \\DomainException {\n    abstract public function __construct(string \$c);\n"
            ."    public static function tooLong(string \$c): static { return new static('Trop long : '.\$c); }\n}", 1];
        yield 'trait déclaré après la classe' => [$class."}\ntrait Other {\n    public function getDetail(): string { return \$this->c; }\n}", 0];
        yield 'new static dans un trait' => [$namespace."trait Formats {\n    public static function tooLong(string \$c): static { return new static('x'.\$c); }\n}\n".$user, 1];
        yield 'new self d\'un trait, utilisé ailleurs' => [$namespace."trait Formats {\n    public static function tooLong(string \$c): self { return new self('x'.\$c); }\n}\nfinal class Other { use Formats; }", 0];
        yield 'affecte $this->message, par un trait' => [$namespace."trait Formats {\n    public function rename(string \$c): void { \$this->message = \$c; }\n}\n".$user, 1];
        yield 'use d\'une fermeture dans une méthode' => [$class."    public static function f(string \$c): \\Closure { return function () use (\$c): string { return \$c; }; }\n}", 0];

        // Héritage (issue #514) : ce que la classe tient de ses parents, après
        // elle-même et ses traits, comme PHP le résout.
        $detail = "    public function getDetail(): ?string { return \$this->c; }\n";
        $abstractBase = "abstract class Base extends \\DomainException {\n";
        $base = $namespace.$abstractBase;
        $child = "final class Problem extends Base {}\n";
        yield 'getDetail d\'un parent hors recensement' => [$base.$detail."}\n".$child, 1];
        yield 'getDetail d\'un parent qui rend le message' => [$base.$message."}\n".$child, 0];
        yield 'getDetail du parent du parent' => [$namespace."abstract class Root extends \\DomainException {\n".$detail."}\n"
            ."abstract class Base extends Root {}\n".$child, 1];
        yield 'getDetail du trait d\'un parent' => [$namespace."trait Formats {\n".$detail."}\n"
            ."abstract class Base extends \\DomainException {\n    use Formats;\n}\n".$child, 1];
        yield 'getDetail de la classe, prioritaire sur celui du parent' => [$base.$detail."}\n"
            ."final class Problem extends Base {\n".$message."}\n", 0];
        yield 'getDetail d\'un trait, prioritaire sur celui du parent' => [$namespace."trait Formats {\n".$message."}\n"
            .$abstractBase.$detail."}\nfinal class Problem extends Base {\n    use Formats;\n}\n", 0];
        yield 'getDetail d\'un parent importé' => ["<?php\nnamespace App\\Z;\nabstract class Base extends \\DomainException {\n".$detail."}\n"
            ."namespace App\\Y;\nuse App\\Z\\Base as Parent_;\nfinal class Problem extends Parent_ {}\n", 1];
        yield 'parent hors des sources, ignoré' => [$namespace."final class Problem extends \\App\\V\\Vendor {}\n", 0];
        yield 'constructeur hérité qui formate' => [$base."    public function __construct(string \$c) { parent::__construct('Trop long : '.\$c); }\n}\n".$child, 1];
        yield 'constructeur hérité au message fixe' => [$base."    public function __construct(?\\Throwable \$p = null) { parent::__construct('Vide.', 0, \$p); }\n}\n".$child
            ."final class Thrower { public function f(\\Throwable \$e): never { throw new Problem(\$e); } }", 0];
        yield 'constructeur de la classe, prioritaire sur celui du parent' => [$base."    public function __construct(string \$c) { parent::__construct('Trop long : '.\$c); }\n}\n"
            ."final class Problem extends Base {\n    public function __construct() { parent::__construct('Vide.'); }\n}\n", 0];
        yield 'affecte $this->message, par un parent' => [$base."    public function rename(string \$c): void { \$this->message = \$c; }\n}\n".$child, 1];
        yield 'new static d\'un parent' => [$base."    public static function tooLong(string \$c): static { return new static('x'.\$c); }\n}\n".$child, 1];
        yield 'new self d\'un parent' => [$base."    public static function tooLong(string \$c): self { return new self('x'.\$c); }\n}\n".$child, 0];

        // `new parent` (issue #514) : le parent de la déclaration où il s'écrit.
        $problem = $namespace."class Problem extends \\DomainException {}\n";
        $build = "    public static function f(string \$c): \\DomainException { return new parent('x'.\$c); }\n";
        yield 'new parent' => [$problem."final class Child extends Problem {\n".$build."}\n", 1];
        yield 'new parent, en casse différente' => [$problem."final class Child extends Problem {\n    public static function f(string \$c): \\DomainException { return new PARENT('x'.\$c); }\n}\n", 1];
        yield 'new parent d\'une autre classe' => [$problem."final class Other extends \\DomainException {\n".$build."}\n", 0];
        yield 'new parent dans un trait' => [$problem."trait Builds {\n".$build."}\nfinal class Child extends Problem {\n    use Builds;\n}\n", 1];

        // Adaptations de trait (issue #514) : `insteadof` choisit, `as` ajoute un nom.
        $good = "trait Good {\n".$message."}\n";
        $bad = "trait Bad {\n".$detail."}\n";
        $adapted = static fn (string $adaptations): string => $namespace.$good.$bad
            ."trait Formats {\n    public function bad(): string { return \$this->c; }\n    public function build(string \$c) { parent::__construct('x'.\$c); }\n}\n"
            ."final class Problem extends \\DomainException {\n    use ".$adaptations."\n}\n";
        yield 'insteadof' => [$adapted('Good, Bad { Bad::getDetail insteadof Good; }'), 1];
        yield 'insteadof, l\'autre sens' => [$adapted('Bad, Good { Good::getDetail insteadof Bad; }'), 0];
        yield 'alias de trait' => [$adapted('Formats { bad as getDetail; }'), 1];
        yield 'alias qualifié, avec visibilité' => [$adapted('Formats { Formats::bad as public getDetail; }'), 1];
        yield 'alias en casse différente' => [$adapted('Formats { BAD as GetDetail; }'), 1];
        yield 'changement de visibilité seul' => [$adapted('Formats { bad as protected; }'), 0];
        yield 'alias en constructeur' => [$adapted('Formats { build as __construct; }'), 1];
        yield 'alias d\'un trait importé par un autre' => [$namespace."trait Formats {\n    public function bad(): string { return \$this->c; }\n}\n"
            ."trait Wraps {\n    use Formats { bad as getDetail; }\n}\nfinal class Problem extends \\DomainException {\n    use Wraps;\n}\n", 1];
        yield 'alias, la classe prioritaire' => [$namespace."trait Formats {\n    public function bad(): string { return \$this->c; }\n}\n"
            ."final class Problem extends \\DomainException {\n    use Formats { bad as getDetail; }\n".$message."}\n", 0];
    }

    /** Un trait déclaré dans un autre fichier que la classe qui l'utilise compte aussi (issue #507). */
    public function testATraitDeclaredInAnotherFileCounts(): void
    {
        $sources = [
            'Formats.php' => "<?php\nnamespace App\\Z;\ntrait Formats {\n    public function getDetail(): string { return \$this->c; }\n}\n",
            'Problem.php' => "<?php\nnamespace App\\Y;\nuse App\\Z\\Formats;\nfinal class Problem extends \\DomainException {\n    use Formats;\n}\n",
        ];

        self::assertSame(['Problem.php : le getDetail() de App\Y\Problem ne rend pas son message'], $this->violations($sources, ['App\Y\Problem']));
    }

    /** Deux déclarations du même nom (une seule se chargerait) sont lues chacune (issue #507). */
    public function testEveryDeclarationOfAClassIsChecked(): void
    {
        $class = "<?php\nnamespace App\\Y;\nfinal class Problem extends \\DomainException {\n";
        $sources = [
            'a.php' => $class."    public function getDetail(): string { return \$this->c; }\n}\n",
            'b.php' => $class."}\n",
        ];

        self::assertSame(['a.php : le getDetail() de App\Y\Problem ne rend pas son message'], $this->violations($sources, ['App\Y\Problem']));
    }

    /**
     * Le témoin du modèle par jetons (issue #514) : pour chaque
     * ProblemExceptionInterface de src/, le getDetail() et le constructeur
     * qu'il retient sont ceux que la réflexion donne — même fichier, et la
     * ligne de leur `function` dans celles de la méthode. Une méthode que la
     * réflexion trouve hors de src/ (classe native, vendor) n'est pas lue :
     * le modèle n'en retient aucune. Les extraits de testTheDetector prouvent
     * le jugement ; celui-ci, que la résolution suit PHP sur le code réel.
     */
    public function testTheTokenModelResolvesMethodsAsPhpDoes(): void
    {
        $byName = $this->byName($this->parseAll($this->sourceFiles()));
        $sources = (string) realpath(self::SOURCES);
        $resolved = 0;
        foreach (DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class) as $problem) {
            $class = new ReflectionClass($problem);
            $methods = $this->methodsOf($byName[strtolower($problem)], $byName);
            $reflected = ['getdetail' => $class->hasMethod('getDetail') ? $class->getMethod('getDetail') : null, '__construct' => $class->getConstructor()];
            foreach ($reflected as $name => $method) {
                $retained = $methods[$name] ?? null;
                $file = $method?->getFileName();
                if (null === $method || false === $file || !str_starts_with((string) $file, $sources.\DIRECTORY_SEPARATOR)) {
                    self::assertNull($retained, $problem.'::'.$name.'() : la réflexion ne le trouve pas dans src/, le modèle si.');

                    continue;
                }
                self::assertNotNull($retained, $problem.'::'.$name.'() : déclaré dans '.$file.', le modèle ne le retient pas.');
                $where = $problem.'::'.$name.'() : le modèle retient '.$retained['path'].':'.$retained['line'].', la réflexion '.$file.':'.$method->getStartLine().'.';
                self::assertSame($file, realpath($retained['path']), $where);
                self::assertGreaterThanOrEqual($method->getStartLine(), $retained['line'], $where);
                self::assertLessThanOrEqual($method->getEndLine(), $retained['line'], $where);
                ++$resolved;
            }
        }

        self::assertGreaterThan(0, $resolved, 'Aucune méthode résolue dans src/ : le témoin ne témoignerait de rien.');
    }

    /** Le détecteur lui-même : sans cette preuve, il pourrait être vert pour de mauvaises raisons. */
    #[DataProvider('snippets')]
    public function testTheDetector(string $code, int $expectedViolations): void
    {
        self::assertCount($expectedViolations, $this->violations(['extrait.php' => $code], ['App\Y\Problem']));
    }

    /**
     * @return array<string, string> chemin => contenu, dans l'ordre trié de {@see PhpSources::files()}
     */
    private function sourceFiles(): array
    {
        $sources = [];
        foreach (PhpSources::files(self::SOURCES) as $path) {
            $sources[$path] = (string) file_get_contents($path);
        }

        return $sources;
    }

    /**
     * @param array<string, string> $sources
     * @param list<string>          $problems
     *
     * @return list<string>
     */
    private function violations(array $sources, array $problems): array
    {
        // Les noms se comparent en minuscules (PHP résout les classes sans
        // tenir compte de la casse) et se rapportent sous leur nom déclaré.
        $declaredNames = array_combine(array_map(strtolower(...), $problems), $problems);
        $parsed = $this->parseAll($sources);
        $byName = $this->byName($parsed);

        $fixesItsMessage = [];
        // classe jugée => sorte de `new` => déclarations où ce `new` la construit
        $builtFrom = [];
        $violations = [];
        foreach ($parsed as $path => $file) {
            foreach (array_intersect_key($file['declarations'], $declaredNames) as $name => $declaration) {
                $class = $declaredNames[$name];
                $methods = $this->methodsOf($declaration, $byName);
                $detail = $methods['getdetail'] ?? null;
                if (null !== $detail && !$detail['returnsMessage']) {
                    $violations[] = $path.' : le getDetail() de '.$class.' ne rend pas son message';
                }
                if ($this->assignsMessage($declaration, $byName)) {
                    $violations[] = $path.' : '.$class.' affecte $this->message';
                }
                $constructor = $methods['__construct'] ?? null;
                if (null !== $constructor) {
                    $fixesItsMessage[$name] = true;
                    if (!$constructor['messageIsLiteral']) {
                        $violations[] = $path.' : le constructeur de '.$class.' compose son message';
                    }
                }
                $builtFrom[$name] = $this->constructionSources($declaration, $byName);
            }
        }

        foreach ($parsed as $path => $file) {
            foreach ($file['constructions'] as [$kind, $writtenIn, $literal, $line]) {
                // Un nom désigne la classe jugée même déclarée hors des sources lues.
                $targets = self::BY_NAME === $kind
                    ? array_intersect([$writtenIn], array_keys($declaredNames))
                    : array_keys(array_filter($builtFrom, static fn (array $sourcesByKind): bool => \in_array($writtenIn, $sourcesByKind[$kind] ?? [], true)));
                foreach ($targets as $target) {
                    if (!$literal && !isset($fixesItsMessage[$target])) {
                        $violations[] = $path.':'.$line.' : new '.$declaredNames[$target].' avec un message non littéral';
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<string, string> $sources chemin => contenu
     *
     * @return array<string, array{declarations: array<string, Declaration>, constructions: list<Construction>}>
     */
    private function parseAll(array $sources): array
    {
        $parsed = [];
        foreach ($sources as $path => $code) {
            $parsed[$path] = $this->parse($path, $code);
        }

        return $parsed;
    }

    /**
     * Les déclarations de tous les fichiers, par nom : traits et parents se
     * cherchent ainsi d'un fichier à l'autre. Les classes jugées, elles, se
     * prennent chacune dans son fichier ({@see self::violations()}), une
     * déclaration en double n'en masquant aucune.
     *
     * @param array<string, array{declarations: array<string, Declaration>, constructions: list<Construction>}> $parsed
     *
     * @return array<string, Declaration>
     */
    private function byName(array $parsed): array
    {
        $byName = [];
        foreach ($parsed as $file) {
            $byName = array_merge($byName, $file['declarations']);
        }

        return $byName;
    }

    /**
     * Les méthodes concrètes que PHP retient pour cette déclaration, par nom
     * en minuscules : les siennes, puis celles de ses traits, puis celles de
     * son parent — l'ordre de PHP, où une méthode de trait remplace celle
     * héritée et cède devant celle de la classe. Un parent ou un trait
     * absent des sources (vendor, classe native) n'est pas lu.
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     * @param list<string>               $seen        déclarations déjà lues, contre un cycle
     *
     * @return array<string, Method>
     */
    private function methodsOf(array $declaration, array $byName, array $seen = []): array
    {
        $seen = [...$seen, $declaration['name']];
        $parent = $declaration['parent'];
        $inherited = null !== $parent && isset($byName[$parent]) && !\in_array($parent, $seen, true)
            ? $this->methodsOf($byName[$parent], $byName, $seen)
            : [];

        return $declaration['methods'] + $this->fromTraits($declaration, $byName, $seen) + $inherited;
    }

    /**
     * Ce que les traits de la déclaration lui apportent, adaptations lues :
     * entre deux traits qui déclarent la même méthode, celui que désigne
     * `insteadof` (le premier nommé sans lui, là où PHP refuserait la
     * classe) ; un alias `[T::]m as [visibilité] nom` ajoute la méthode sous
     * ce nom, sans retirer l'originale. Une collision d'alias, que PHP
     * refuse aussi, n'est pas tranchée finement : l'alias l'emporte.
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     * @param list<string>               $seen
     *
     * @return array<string, Method>
     */
    private function fromTraits(array $declaration, array $byName, array $seen): array
    {
        // méthode => trait => corps
        $candidates = [];
        foreach ($declaration['traits'] as $trait) {
            if (!isset($byName[$trait]) || \in_array($trait, $seen, true)) {
                continue;
            }
            foreach ($this->methodsOf($byName[$trait], $byName, $seen) as $method => $body) {
                $candidates[$method][$trait] ??= $body;
            }
        }

        $methods = [];
        foreach ($candidates as $method => $byTrait) {
            $chosen = $declaration['insteadof'][$method] ?? null;
            $body = null !== $chosen ? $byTrait[$chosen] ?? null : null;
            $methods[$method] = $body ?? array_first($byTrait);
        }
        foreach ($declaration['aliases'] as [$trait, $method, $alias]) {
            $byTrait = $candidates[$method] ?? [];
            $body = null === $trait ? array_first($byTrait) : $byTrait[$trait] ?? null;
            if (null !== $body) {
                $methods[$alias] = $body;
            }
        }

        return $methods;
    }

    /**
     * La déclaration, puis son parent, le parent de celui-ci… tant qu'ils
     * sont dans les sources.
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     *
     * @return list<Declaration>
     */
    private function ancestry(array $declaration, array $byName): array
    {
        $ancestry = [$declaration];
        $seen = [$declaration['name']];
        while (null !== ($parent = $declaration['parent']) && isset($byName[$parent]) && !\in_array($parent, $seen, true)) {
            $declaration = $byName[$parent];
            $ancestry[] = $declaration;
            $seen[] = $parent;
        }

        return $ancestry;
    }

    /**
     * Les traits qu'utilise la déclaration, directement ou par un autre
     * trait — pas ceux de ses parents.
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     * @param list<string>               $seen        traits déjà lus, contre un cycle
     *
     * @return list<string>
     */
    private function traitsOf(array $declaration, array $byName, array $seen = []): array
    {
        $traits = [];
        foreach ($declaration['traits'] as $trait) {
            if (!isset($byName[$trait]) || \in_array($trait, [...$seen, ...$traits], true)) {
                continue;
            }
            $traits = [...$traits, $trait, ...$this->traitsOf($byName[$trait], $byName, [...$seen, ...$traits, $trait])];
        }

        return array_values(array_unique($traits));
    }

    /**
     * Une affectation de `$this->message` compte pour la classe où qu'elle
     * s'écrive : dans la classe, ses traits, ses parents ou leurs traits
     * (la propriété de {@see \Exception} est protégée, un parent l'atteint).
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     */
    private function assignsMessage(array $declaration, array $byName): bool
    {
        foreach ($this->ancestry($declaration, $byName) as $ancestor) {
            $traits = array_map(static fn (string $trait): array => $byName[$trait], $this->traitsOf($ancestor, $byName));
            if (array_any([$ancestor, ...$traits], static fn (array $declared): bool => $declared['assignsMessage'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Par sorte de `new` relative ({@see self::RELATIVE_NAMES}), les
     * déclarations où il construit cette classe — son nom la construit
     * n'importe où : `new self` dans la classe ou ses traits ;
     * `new static` aussi dans ses parents et leurs traits (la liaison
     * statique tardive construit la sous-classe appelée) ; `new parent`
     * dans ses sous-classes directes et leurs traits.
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     *
     * @return array<string, list<string>>
     */
    private function constructionSources(array $declaration, array $byName): array
    {
        $name = $declaration['name'];
        $static = [];
        foreach ($this->ancestry($declaration, $byName) as $ancestor) {
            $static = [...$static, $ancestor['name'], ...$this->traitsOf($ancestor, $byName)];
        }
        $parent = [];
        foreach ($byName as $child) {
            if ($name === $child['parent']) {
                $parent = [...$parent, $child['name'], ...$this->traitsOf($child, $byName)];
            }
        }

        return [
            'self' => [$name, ...$this->traitsOf($declaration, $byName)],
            'static' => $static,
            'parent' => $parent,
        ];
    }

    /**
     * Les déclarations (classes, interfaces, traits, enums) et les `new` d'un
     * fichier, noms résolus en minuscules par {@see ClassNameResolver}.
     *
     * @return array{declarations: array<string, Declaration>, constructions: list<Construction>}
     */
    private function parse(string $path, string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $namespace = '';
        $imports = [];
        // La déclaration en cours et la profondeur de son corps : ce qui
        // s'écrit après sa `}` fermante ne lui appartient plus.
        $current = null;
        $bodyDepth = 0;
        $depth = 0;
        // Ce que chaque déclaration porte, assemblé à la fin.
        $parents = [];
        $traits = [];
        $adaptations = [];
        $methods = [];
        $assignsMessage = [];
        $constructions = [];

        foreach ($tokens as $index => $token) {
            if (PhpSources::opensBrace($token)) {
                ++$depth;
            } elseif ($token->is('}')) {
                if (--$depth < $bodyDepth) {
                    $current = null;
                }
            } elseif ($token->is(\T_NAMESPACE)) {
                $namespace = ClassNameResolver::namespaceDeclaredAt($tokens, $index);
                $imports = [];
            } elseif ($token->is(\T_USE) && null === $current) {
                // Un import, au premier niveau ou dans un `namespace { … }`.
                $imports = array_merge($imports, ClassNameResolver::imports($tokens, $index + 1));
            } elseif ($token->is(\T_USE) && null !== $current && $depth === $bodyDepth) {
                [$used, $next] = $this->usedTraits($tokens, $index + 1, $namespace, $imports);
                $traits[$current] = [...$traits[$current] ?? [], ...$used];
                if ($this->tokenAt($tokens, $next, '{')) {
                    $adaptations[$current][] = $this->adaptations($tokens, $next, $namespace, $imports);
                }
            } elseif ($this->declaresAt($tokens, $index)) {
                $current = ltrim($namespace.'\\'.strtolower($tokens[$index + 1]->text), '\\');
                $bodyDepth = $depth + 1;
                $parents[$current] = $token->is(\T_CLASS) ? $this->extendedAt($tokens, $index + 2, $namespace, $imports) : null;
            } elseif ($token->is(\T_FUNCTION) && null !== $current && $depth === $bodyDepth) {
                // Une méthode abstraite n'a pas de corps : elle ne déclare rien ici.
                $body = $this->bodyOf($tokens, $index);
                if (null !== $body) {
                    $methods[$current][$this->functionName($tokens, $index)] = [
                        'path' => $path,
                        'line' => $token->line,
                        'returnsMessage' => $this->returnsTheMessage($tokens, $body),
                        'messageIsLiteral' => $this->parentMessageIsLiteral($tokens, $body),
                    ];
                }
            } elseif ($token->is(\T_NEW) && $this->tokenAt($tokens, $index + 1, self::NAME_TOKENS) && $this->tokenAt($tokens, $index + 2, '(')) {
                [$kind, $class] = $this->instantiated($tokens[$index + 1], $namespace, $imports, $current);
                $constructions[] = [$kind, $class, $this->messageIsLiteral($this->arguments($tokens, $index + 2)), $token->line];
            } elseif (null !== $current && $this->assignsTheMessageAt($tokens, $index)) {
                $assignsMessage[$current] = true;
            }
        }

        $declarations = [];
        foreach ($parents as $name => $parent) {
            $declarations[$name] = [
                'name' => $name,
                'parent' => $parent,
                'traits' => $traits[$name] ?? [],
                'insteadof' => array_merge(...array_column($adaptations[$name] ?? [], 'insteadof')),
                'aliases' => array_merge(...array_column($adaptations[$name] ?? [], 'aliases')),
                'methods' => $methods[$name] ?? [],
                'assignsMessage' => $assignsMessage[$name] ?? false,
            ];
        }

        return ['declarations' => $declarations, 'constructions' => $constructions];
    }

    /**
     * La classe qu'étend la déclaration dont le nom précède $index
     * (`class X extends Y`), résolue ; null sans `extends`.
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     */
    private function extendedAt(array $tokens, int $index, string $namespace, array $imports): ?string
    {
        return $this->tokenAt($tokens, $index, \T_EXTENDS) && $this->tokenAt($tokens, $index + 1, ClassNameResolver::NAMES)
            ? ClassNameResolver::resolve($tokens[$index + 1], $namespace, $imports)
            : null;
    }

    /**
     * Les adaptations du bloc `{ … }` d'un `use` de traits ouvert en $open :
     * `T::m insteadof U, V;` désigne le trait dont vient m, `[T::]m as
     * [visibilité] [alias];` ajoute un alias — un simple changement de
     * visibilité n'en ajoute aucun. Noms de méthode en minuscules.
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     *
     * @return array{insteadof: array<string, string>, aliases: list<array{?string, string, string}>}
     */
    private function adaptations(array $tokens, int $open, string $namespace, array $imports): array
    {
        $insteadof = [];
        $aliases = [];
        $statement = [];
        for ($index = $open + 1, $count = \count($tokens); $index < $count && !$tokens[$index]->is('}'); ++$index) {
            if (!$tokens[$index]->is(';')) {
                $statement[] = $tokens[$index];

                continue;
            }
            // [T, ::, m, …] ou [m, …] : le trait éventuel, puis la méthode.
            $qualified = $this->tokenAt($statement, 1, \T_DOUBLE_COLON);
            $trait = $qualified ? ClassNameResolver::resolve($statement[0], $namespace, $imports) : null;
            $method = strtolower($statement[$qualified ? 2 : 0]->text ?? '');
            $operator = $statement[$qualified ? 3 : 1] ?? null;
            $alias = end($statement);
            if (null !== $trait && null !== $operator && $operator->is(\T_INSTEADOF)) {
                $insteadof[$method] = $trait;
            } elseif (null !== $operator && $operator->is(\T_AS) && false !== $alias && $alias->is(\T_STRING) && $alias !== $operator) {
                $aliases[] = [$trait, $method, strtolower($alias->text)];
            }
            $statement = [];
        }

        return ['insteadof' => $insteadof, 'aliases' => $aliases];
    }

    /**
     * @param list<PhpToken>              $tokens
     * @param int|string|list<int|string> $kind
     */
    private function tokenAt(array $tokens, int $index, int|string|array $kind): bool
    {
        return isset($tokens[$index]) && $tokens[$index]->is($kind);
    }

    /**
     * Le nom, en minuscules, de la fonction déclarée en $function : celui qui
     * suit `function`, ou `&` pour une fonction qui rend une référence
     * (`function &getDetail()`).
     *
     * @param list<PhpToken> $tokens
     */
    private function functionName(array $tokens, int $function): string
    {
        $name = $tokens[$function + 1] ?? null;
        if (null !== $name && '&' === $name->text) {
            $name = $tokens[$function + 2] ?? null;
        }

        return strtolower($name->text ?? '');
    }

    /**
     * Le jeton en $index commence-t-il `$this->message =` (ou `.=`, `??=`) ?
     * Affecter la propriété de {@see \Exception} contourne le constructeur :
     * le getDetail() de HasProblemType la rendrait telle quelle. Le nom de
     * propriété tient compte de la casse, comme PHP.
     *
     * @param list<PhpToken> $tokens
     */
    private function assignsTheMessageAt(array $tokens, int $index): bool
    {
        return $tokens[$index]->is(\T_VARIABLE) && '$this' === $tokens[$index]->text
            && $this->tokenAt($tokens, $index + 1, \T_OBJECT_OPERATOR)
            && $this->tokenAt($tokens, $index + 2, \T_STRING) && 'message' === $tokens[$index + 2]->text
            && $this->tokenAt($tokens, $index + 3, self::ASSIGNMENTS);
    }

    /**
     * Le jeton en $index ouvre-t-il une déclaration nommée ? Un mot-clé de
     * {@see PhpSources::CLASS_LIKE} suivi de son nom : ni `new class`, ni
     * `Foo::class`, ni `class:`/`trait:` en argument nommé, que le lexeur rend
     * aussi en mots-clés.
     *
     * @param list<PhpToken> $tokens
     */
    private function declaresAt(array $tokens, int $index): bool
    {
        return $tokens[$index]->is(PhpSources::CLASS_LIKE)
            && !$this->tokenAt($tokens, $index - 1, [\T_NEW, \T_DOUBLE_COLON])
            && $this->tokenAt($tokens, $index + 1, \T_STRING);
    }

    /**
     * Les traits d'un `use A, B;` (ou `use A, B { … }`) écrit dans le corps
     * d'une déclaration, le premier nom en $index : des noms de classe, que
     * l'espace de noms et les imports résolvent ; et la position du jeton qui
     * suit le dernier nom (`;` ou l'accolade des adaptations).
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     *
     * @return array{list<string>, int}
     */
    private function usedTraits(array $tokens, int $index, string $namespace, array $imports): array
    {
        $traits = [];
        while ($this->tokenAt($tokens, $index, ClassNameResolver::NAMES)) {
            $traits[] = ClassNameResolver::resolve($tokens[$index], $namespace, $imports);
            ++$index;
            if (!$this->tokenAt($tokens, $index, ',')) {
                break;
            }
            ++$index;
        }

        return [$traits, $index];
    }

    /**
     * La sorte de `new` et ce qu'il désigne, en minuscules : `self`, `static`
     * et `parent` (casse indifférente) se rapportent à la déclaration où ils
     * s'écrivent (aucune hors d'une déclaration), que
     * {@see self::constructionSources()} relie aux classes construites ; tout
     * autre nom se résout par {@see ClassNameResolver}.
     *
     * @param array<string, string> $imports
     *
     * @return array{string, string}
     */
    private function instantiated(PhpToken $name, string $namespace, array $imports, ?string $current): array
    {
        $relative = strtolower($name->text);
        if (\in_array($relative, self::RELATIVE_NAMES, true)) {
            return [$relative, (string) $current];
        }

        return [self::BY_NAME, ClassNameResolver::resolve($name, $namespace, $imports)];
    }

    /**
     * Arguments d'un appel dont la parenthèse ouvrante est en $open, découpés
     * sur les virgules de premier niveau.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<list<PhpToken>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $arguments = [[]];
        $level = 0;
        for ($index = $open + 1, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token->is(['(', '[']) || PhpSources::opensBrace($token)) {
                ++$level;
            } elseif ($token->is([')', ']', '}'])) {
                if (0 === $level) {
                    break;
                }
                --$level;
            } elseif ($token->is(',') && 0 === $level) {
                $arguments[] = [];

                continue;
            }
            $arguments[array_key_last($arguments)][] = $token;
        }

        return array_values(array_filter($arguments, static fn (array $argument): bool => [] !== $argument));
    }

    /**
     * Le message est le premier argument positionnel, ou l'argument nommé
     * `message` ; absent, il reste celui que la classe déclare.
     *
     * @param list<list<PhpToken>> $arguments
     */
    private function messageIsLiteral(array $arguments): bool
    {
        foreach ($arguments as $position => $argument) {
            $named = $this->tokenAt($argument, 1, ':') && $argument[0]->is(\T_STRING);
            if ($named && 'message' === $argument[0]->text) {
                return $this->isLiteral(\array_slice($argument, 2));
            }
            if (!$named && 0 === $position) {
                return $this->isLiteral($argument);
            }
        }

        return true;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private function isLiteral(array $tokens): bool
    {
        if (1 === \count($tokens)) {
            return $tokens[0]->is(\T_CONSTANT_ENCAPSED_STRING);
        }

        return [] !== $tokens && array_all($tokens, static fn (PhpToken $token): bool => $token->is(self::LITERAL_HEREDOC_TOKENS));
    }

    /**
     * L'accolade ouvrante du corps de la méthode déclarée en $function ; null
     * pour une méthode abstraite (`;` après la signature). Les parenthèses
     * sont comptées : un paramètre promu à crochets de propriété
     * (`public string $x { get => … }`) n'ouvre pas le corps.
     *
     * @param list<PhpToken> $tokens
     */
    private function bodyOf(array $tokens, int $function): ?int
    {
        $parentheses = 0;
        for ($index = $function + 1, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token->is('(')) {
                ++$parentheses;
            } elseif ($token->is(')')) {
                --$parentheses;
            } elseif (0 === $parentheses && $token->is([';', '{'])) {
                return $token->is('{') ? $index : null;
            }
        }

        return null;
    }

    /**
     * Le corps qui s'ouvre en $body est-il exactement
     * `{ return $this->getMessage(); }` — le `detail` de HasProblemType, que le
     * constructeur et les sites de construction gardent littéral ?
     *
     * @param list<PhpToken> $tokens
     */
    private function returnsTheMessage(array $tokens, int $body): bool
    {
        $texts = array_map(static fn (PhpToken $token): string => strtolower($token->text), \array_slice($tokens, $body + 1, \count(self::RETURNS_THE_MESSAGE)));

        return self::RETURNS_THE_MESSAGE === $texts;
    }

    /**
     * Dans le constructeur dont le corps s'ouvre en $body : le message passé à
     * `parent::__construct` est-il littéral, à chacun de ses appels (un par
     * branche, par exemple) ? Sans appel au parent, le message reste celui par
     * défaut (vide) : littéral. `{$x}` et `${x}` ouvrent une
     * accolade comme `{` ({@see PhpSources::opensBrace()}, issue #507).
     *
     * @param list<PhpToken> $tokens
     */
    private function parentMessageIsLiteral(array $tokens, int $body): bool
    {
        $literal = true;
        $depth = 0;
        for ($index = $body, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (PhpSources::opensBrace($token)) {
                ++$depth;
            } elseif ($token->is('}') && 0 === --$depth) {
                break;
            } elseif ($depth > 0 && $token->is(\T_STRING) && 'parent' === strtolower($token->text)
                && $this->tokenAt($tokens, $index + 1, \T_DOUBLE_COLON)
                && '__construct' === strtolower($tokens[$index + 2]->text ?? '')) {
                $literal = $literal && $this->messageIsLiteral($this->arguments($tokens, $index + 3));
            }
        }

        return $literal;
    }
}
