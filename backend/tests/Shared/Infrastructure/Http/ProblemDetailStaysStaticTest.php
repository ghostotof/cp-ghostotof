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
 *  - le constructeur qu'elle retient ne compose jamais son message : il le
 *    fixe (un littéral à chacun de ses `parent::__construct`) — ses sites de
 *    construction importent alors peu (une cause en `previous`, par
 *    exemple) —, ou transmet tel quel l'un de ses paramètres ;
 *  - chaque `new` (y compris `new self`/`new static`/`new parent`, alias
 *    `use … as` résolus, dans n'importe quel contexte syntaxique) d'une
 *    classe sans constructeur, ou dont le constructeur transmet, ne passe à
 *    ce paramètre (le premier, ou `message:`, pour le constructeur natif)
 *    qu'une chaîne littérale, un heredoc sans variable, une constante de
 *    classe dont la valeur en est une (issue #523) ou rien.
 *
 * Les méthodes jugées sont celles que PHP retient (issue #514) : celles de la
 * classe, puis celles de ses traits (directement ou par un autre trait,
 * `insteadof` et alias `as` lus), puis celles de son parent, résolu de même
 * — un parent qui n'est pas lui-même une ProblemExceptionInterface compris ;
 * une méthode abstraite ne compte pas. Un `new self` construit la classe où
 * il s'écrit, ou chaque classe qui utilise le trait où il s'écrit ; un
 * `new static`, aussi chaque sous-classe ; un `new parent`, le parent. Une
 * affectation de `$this->message` compte où qu'elle s'écrive dans cette
 * lignée. Le message suit la chaîne des constructeurs : le premier argument
 * d'un `parent::__construct` n'est le message que si ce parent est natif ;
 * lu dans les sources, le constructeur du parent dit lui-même de quels
 * paramètres son message dépend ({@see self::messageSource()}) — un
 * littéral qu'on lui passe le fixe, même s'il le compose. Les noms se résolvent comme PHP le fait — casse indifférente,
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
 *    il n'est pas lu — la classe garde ce qu'elle déclare elle-même, et la
 *    méthode d'un parent lu reste jugée même si un trait non lu la remplace ;
 *  - un parent dont le message dépend d'autre chose que de ses paramètres
 *    (un appel, une propriété, une variable locale, une constante composée
 *    avec un paramètre) : la classe qui l'appelle est refusée, quoi qu'elle
 *    lui passe — prudence assumée ;
 *  - une constante n'est un littéral que seule, et de valeur littérale
 *    (issue #523) : composée (`self::A.' x'`), faite d'une autre constante,
 *    concaténée au site, héritée d'une interface (le modèle ne lit pas
 *    `implements`), déclarée hors des sources, ou un cas d'enum
 *    (`Kind::Cas->value`), elle est refusée — prudence assumée, aucun cas
 *    dans src/. Une constante globale nue (`MESSAGE`) aussi : `define()` lui
 *    donne sa valeur à l'exécution, et le modèle ne lit pas les `const`
 *    d'espace de noms. Écrits dans un trait, `self::` et `static::` se lisent
 *    sur le trait, pas sur la classe qui l'utilise : refusés si seule cette
 *    classe déclare la constante, et `static::` n'y voit pas les
 *    sous-classes de cette classe, qui pourraient la redéfinir composée ;
 *  - `Ancêtre::__construct(…)`, appelé par son nom, est lu comme le
 *    constructeur du parent ;
 *  - une collision que PHP refuse (deux traits sans `insteadof`, un alias
 *    qui en heurte un autre) : le premier trait nommé, ou l'alias,
 *    l'emporte — la classe ne se chargerait pas ;
 *  - `$this->message` modifié autrement que par affectation directe
 *    (référence, Reflection, `Closure::bind`), et un paramètre réaffecté par
 *    un passage par référence ({@see self::reassignedIn()}) ;
 *  - l'import groupé (`use Foo\{A, B};`) : ImportsStaySortedTest le refuse
 *    déjà dans src/ et tests/ ;
 *  - une classe déclarée dans le corps d'une méthode ou d'une fonction : les
 *    fonctions hors classe sont interdites dans src/ (issue #432), et une
 *    déclaration conditionnelle n'y existe pas.
 *
 * Une Method est une méthode concrète : où elle s'écrit, si elle rend le
 * message, ses paramètres (noms sans `$`), ceux qu'elle réaffecte, et les
 * arguments de chacun de ses `parent::__construct`. Retenue pour une classe,
 * elle porte aussi son `context`, la déclaration de la lignée qui la détient
 * (elle-même ou par un trait) : celle dont `parent` désigne le parent. Un
 * Construction est un `new` : sa sorte (BY_NAME ou RELATIVE_NAMES), la
 * classe nommée ou la déclaration où il s'écrit, ses arguments, sa ligne,
 * et la déclaration où il s'écrit (null hors de toute), d'où se lisent les
 * constantes `self::`, `parent::` et `static::` de ses arguments. Une
 * Declaration porte ses constantes : nom => valeur littérale ou non.
 * Un MessageSource dit de quoi dépend le message d'un constructeur : par
 * paramètre, composé ou transmis tel quel ; aucun s'il le fixe ; false s'il
 * dépend d'autre chose ({@see self::messageSource()}).
 *
 * @phpstan-type Method array{
 *     path: string,
 *     line: int,
 *     returnsMessage: bool,
 *     parameters: list<string>,
 *     reassigned: list<string>,
 *     parentCalls: list<list<list<PhpToken>>>,
 *     context?: string,
 * }
 * @phpstan-type Declaration array{
 *     name: string,
 *     parent: ?string,
 *     traits: list<string>,
 *     insteadof: array<string, string>,
 *     aliases: list<array{?string, string, string}>,
 *     methods: array<string, Method>,
 *     assignsMessage: bool,
 *     constants: array<string, bool>,
 * }
 * @phpstan-type Construction array{string, string, list<list<PhpToken>>, int, ?string}
 * @phpstan-type MessageSource false|array<string, bool>
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

    /** Le message de {@see \Exception::__construct()} : son premier paramètre, `$message`. */
    private const array NATIVE_MESSAGE = [[0, 'message']];

    /** Ce qui, écrit après une variable, la réaffecte. */
    private const array VARIABLE_ASSIGNMENTS = ['=', \T_PLUS_EQUAL, \T_MINUS_EQUAL, \T_MUL_EQUAL, \T_DIV_EQUAL, \T_CONCAT_EQUAL, \T_MOD_EQUAL,
        \T_AND_EQUAL, \T_OR_EQUAL, \T_XOR_EQUAL, \T_SL_EQUAL, \T_SR_EQUAL, \T_POW_EQUAL, \T_COALESCE_EQUAL];

    /** @var array<string, array{declarations: array<string, Declaration>, constructions: list<Construction>}>|null */
    private static ?array $sourceModel = null;

    /** Ce qui, écrit après `$this->message`, lui affecte une valeur. */
    private const array ASSIGNMENTS = ['=', \T_CONCAT_EQUAL, \T_COALESCE_EQUAL];
    private const array LITERAL_HEREDOC_TOKENS = [\T_START_HEREDOC, \T_ENCAPSED_AND_WHITESPACE, \T_END_HEREDOC];

    /** Le corps de `getDetail()` qui rend le message, jetons en minuscules après l'accolade ouvrante. */
    private const array RETURNS_THE_MESSAGE = ['return', '$this', '->', 'getmessage', '(', ')', ';', '}'];

    public function testEveryProblemDetailInSourcesIsALiteral(): void
    {
        $problems = DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class);
        self::assertNotEmpty($problems, 'Aucune ProblemExceptionInterface trouvée : le garde-fou ne garderait rien.');
        self::assertSame([], array_diff(array_keys(self::DYNAMIC_DETAIL_ALLOWED), $problems), 'Entrée admise qui n\'existe plus : la retirer.');
        $problems = array_values(array_diff($problems, array_keys(self::DYNAMIC_DETAIL_ALLOWED)));

        self::assertSame([], $this->violationsIn($this->sourceModel(), $problems));
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
        // Le message suit la chaîne des constructeurs (relecture de #514) :
        // le premier argument de `parent::__construct` n'est le message que si
        // ce parent est natif ; lu dans les sources, il dit lui-même lequel de
        // ses paramètres le devient, ou s'il le fixe, ou s'il le compose.
        $forwards = $base."    public function __construct(string \$m) { parent::__construct(\$m); }\n}\n";
        $thrower = static fn (string $arguments): string => "final class Thrower { public function f(string \$c, \\Throwable \$e): never { throw new Problem({$arguments}); } }";
        yield 'constructeur hérité qui transmet, site littéral' => [$forwards.$child.$thrower("'Vide.'"), 0];
        yield 'constructeur hérité qui transmet, site composé' => [$forwards.$child.$thrower("'x'.\$c"), 1];
        yield 'littéral passé à un parent qui transmet' => [$forwards."final class Problem extends Base {\n    public function __construct() { parent::__construct('Vide.'); }\n}\n", 0];
        yield 'littéral nommé passé à un parent qui transmet' => [$forwards."final class Problem extends Base {\n    public function __construct() { parent::__construct(m: 'Vide.'); }\n}\n", 0];
        yield 'composé passé à un parent qui transmet' => [$forwards."final class Problem extends Base {\n    public function __construct(string \$c) { parent::__construct('x'.\$c); }\n}\n", 1];
        yield 'littéral passé à un parent qui compose' => [$base."    public function __construct(string \$p, string \$c) { parent::__construct(\$p.\$c); }\n}\n"
            ."final class Problem extends Base {\n    public function __construct(string \$c) { parent::__construct('Préfixe', \$c); }\n}\n", 1];
        yield 'argument passé à un parent au message fixe' => [$base."    public function __construct(public readonly string \$field) { parent::__construct('Vide.'); }\n}\n"
            ."final class Problem extends Base {\n    public function __construct(string \$f) { parent::__construct(\$f); }\n}\n", 0];
        $own = $namespace."final class Problem extends \\DomainException {\n    public function __construct(?\\Throwable \$p, string \$m) { parent::__construct(\$m, 0, \$p); }\n}\n";
        yield 'constructeur qui transmet, site littéral' => [$own.$thrower("\$e, 'Vide.'"), 0];
        yield 'constructeur qui transmet, site composé' => [$own.$thrower("\$e, 'x'.\$c"), 1];
        yield 'constructeur qui transmet, site nommé composé' => [$own.$thrower("\$e, m: 'x'.\$c"), 1];
        yield 'constructeur qui transmet, autre argument composé' => [$own.$thrower("new \\RuntimeException(\$c), 'Vide.'"), 0];
        yield 'paramètre réaffecté avant transmission' => [$namespace."final class Problem extends \\DomainException {\n    public function __construct(string \$m) { \$m = 'x'.\$m; parent::__construct(\$m); }\n}\n".$thrower("'Vide.'"), 1];
        // Seconde relecture de #514 : ce qui réaffecte un paramètre sans `=`,
        // la décompression, les parenthèses, l'appel nommé du parent.
        $forwarding = static fn (string $body, string $parameters = 'string $m'): string => $namespace."final class Problem extends \\DomainException {\n    public function __construct({$parameters}) { {$body} }\n}\n";
        yield 'paramètre lu avant transmission' => [$forwarding("if ('' === \$m) { return; } parent::__construct(\$m);").$thrower("'Vide.'"), 0];
        yield 'paramètre réaffecté par foreach' => [$forwarding("foreach (\$v as \$k => \$m) {} parent::__construct(\$m);", 'string $m, array $v').$thrower("'Vide.', [\$c]"), 1];
        yield 'paramètre déstructuré' => [$forwarding("[\$m] = \$v; parent::__construct(\$m);", 'string $m, array $v').$thrower("'Vide.', [\$c]"), 1];
        yield 'paramètre déstructuré par list()' => [$forwarding("list(, \$m) = \$v; parent::__construct(\$m);", 'string $m, array $v').$thrower("'Vide.', [\$c]"), 1];
        yield 'paramètre réaffecté par catch' => [$forwarding("try { f(); } catch (\\Exception \$m) {} parent::__construct(\$m);").$thrower("'Vide.'"), 1];
        yield 'paramètre réaffecté par extract()' => [$forwarding("extract(\$v); parent::__construct(\$m);", 'string $m, array $v').$thrower("'Vide.', [\$c]"), 1];
        yield 'paramètre réaffecté par une variable variable' => [$forwarding("\$n = 'm'; \$\$n = \$c; parent::__construct(\$m);", 'string $m, string $c').$thrower("'Vide.', \$c"), 1];
        yield 'paramètre transmis entre parenthèses' => [$forwarding('parent::__construct(($m));').$thrower("'Vide.'"), 0];
        yield 'site décompressé' => [$own.$thrower("...[\$e, 'x'.\$c]"), 1];
        yield 'décompression vers un parent qui transmet' => [$namespace."abstract class Base extends \\DomainException {\n    public function __construct(?\\Throwable \$p, string \$m) { parent::__construct(\$m); }\n}\n"
            ."final class Problem extends Base {\n    public function __construct(string ...\$a) { parent::__construct(...\$a); }\n}\n".$thrower("'x'.\$c"), 1];
        yield 'constructeur du parent appelé par son nom' => [$forwarding("\\DomainException::__construct('x'.\$m);"), 1];
        yield 'paramètre sous attribut, transmis' => [$namespace."final class Problem extends \\DomainException {\n    public function __construct(#[\\SensitiveParameter] string \$p, string \$m) { parent::__construct(\$m); }\n}\n".$thrower("\$c, 'Vide.'"), 0];
        yield 'transmis sur deux niveaux, site composé' => [$namespace."abstract class Root extends \\DomainException {\n    public function __construct(string \$r) { parent::__construct(\$r); }\n}\n"
            ."abstract class Base extends Root {\n    public function __construct(?\\Throwable \$p, string \$b) { parent::__construct(\$b); }\n}\n".$child.$thrower("\$e, 'x'.\$c"), 1];
        yield 'parent qui compose avec autre chose qu\'un paramètre' => [$base."    public function __construct(string \$m) { parent::__construct(self::describe(\$m)); }\n}\n"
            ."final class Problem extends Base {\n    public function __construct() { parent::__construct('Vide.'); }\n}\n", 1];
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
        yield 'insteadof vers un trait hors des sources' => [$adapted('\\Vendor\\Good, Bad { \\Vendor\\Good::getDetail insteadof Bad; }'), 0];
        yield 'alias de trait' => [$adapted('Formats { bad as getDetail; }'), 1];
        yield 'alias qualifié, avec visibilité' => [$adapted('Formats { Formats::bad as public getDetail; }'), 1];
        yield 'alias en casse différente' => [$adapted('Formats { BAD as GetDetail; }'), 1];
        yield 'changement de visibilité seul' => [$adapted('Formats { bad as protected; }'), 0];
        yield 'alias en constructeur' => [$adapted('Formats { build as __construct; }'), 1];
        yield 'alias d\'un trait importé par un autre' => [$namespace."trait Formats {\n    public function bad(): string { return \$this->c; }\n}\n"
            ."trait Wraps {\n    use Formats { bad as getDetail; }\n}\nfinal class Problem extends \\DomainException {\n    use Wraps;\n}\n", 1];
        yield 'alias, la classe prioritaire' => [$namespace."trait Formats {\n    public function bad(): string { return \$this->c; }\n}\n"
            ."final class Problem extends \\DomainException {\n    use Formats { bad as getDetail; }\n".$message."}\n", 0];

        // Constantes de classe (issue #523) : une constante dont la valeur est
        // un littéral de chaîne est un littéral, au site comme au constructeur.
        $messages = $namespace."final class Messages {\n    public const string EMPTY = 'Vide.';\n    public static function EMPTY(): string { return ''; }\n}\n";
        $siteIn = static fn (string $body, string $argument): string => "final class Thrower {\n{$body}    public function f(string \$c, string \$x): never { throw new Problem({$argument}); }\n}\n";
        $constant = "    private const string M = 'Vide.';\n";
        yield 'site, self::' => [$namespace.$siteIn($constant, 'self::M'), 0];
        yield 'site, constante d\'une classe importée' => ["<?php\nnamespace App\\Z;\nfinal class Messages {\n    public const string EMPTY = 'Vide.';\n}\n"
            ."namespace App\\X;\nuse App\\Y\\Problem;\nuse App\\Z\\Messages;\nthrow new Problem(Messages::EMPTY);", 0];
        yield 'site, constante d\'une classe importée par alias' => ["<?php\nnamespace App\\Z;\nfinal class Messages {\n    public const string EMPTY = 'Vide.';\n}\n"
            ."namespace App\\X;\nuse App\\Y\\Problem;\nuse App\\Z\\Messages as Texts;\nthrow new Problem(message: Texts::EMPTY);", 0];
        yield 'site, nom complet de la classe' => [$messages."throw new Problem(\\App\\Y\\Messages::EMPTY);", 0];
        yield 'site, constante héritée par self::' => [$namespace."abstract class Texts {\n".$constant."}\n"
            ."final class Thrower extends Texts {\n    public function f(): never { throw new Problem(self::M); }\n}\n", 0];
        yield 'site, parent::' => [$namespace."abstract class Texts {\n".$constant."}\n"
            ."final class Thrower extends Texts {\n    public function f(): never { throw new Problem(parent::M); }\n}\n", 0];
        yield 'site, static::' => [$namespace.$siteIn($constant, 'static::M'), 0];
        yield 'site, constante d\'un trait' => [$namespace."trait Texts {\n".$constant."}\n"
            ."final class Thrower {\n    use Texts;\n    public function f(): never { throw new Problem(self::M); }\n}\n", 0];
        yield 'site, constantes en liste' => [$namespace.$siteIn("    const A = 'Vide.', M = 'Plein.';\n", 'self::M'), 0];
        yield 'constructeur, self::' => [$namespace."final class Problem extends \\DomainException {\n".$constant
            ."    public function __construct(?\\Throwable \$p = null) { parent::__construct(self::M, 0, \$p); }\n}\n", 0];
        yield 'constructeur, constante héritée' => [$base.$constant."}\n"
            ."final class Problem extends Base {\n    public function __construct() { parent::__construct(self::M); }\n}\n", 0];
        yield 'constructeur, constante d\'une autre classe' => [$messages."final class Problem extends \\DomainException {\n"
            ."    public function __construct() { parent::__construct(Messages::EMPTY); }\n}\n", 0];
        yield 'constructeur, static:: sans redéfinition' => [$namespace."class Problem extends \\DomainException {\n".$constant
            ."    public function __construct() { parent::__construct(static::M); }\n}\nfinal class Child extends Problem {}\n", 0];

        // Ce qui n'est pas une constante au littéral reste refusé (issue #523).
        yield 'constructeur, static:: redéfinie composée' => [$namespace."class Problem extends \\DomainException {\n".$constant
            ."    public function __construct() { parent::__construct(static::M); }\n}\nfinal class Child extends Problem {\n    protected const string M = 'x'.\\PHP_EOL;\n}\n", 1];
        yield 'site, constante composée' => [$namespace.$siteIn("    private const string A = 'Vide.';\n    private const string M = self::A.' Plein.';\n", 'self::M'), 1];
        yield 'site, constante d\'une autre constante' => [$namespace.$siteIn("    private const string A = 'Vide.';\n    private const string M = self::A;\n", 'self::M'), 1];
        yield 'site, constante concaténée' => [$namespace.$siteIn($constant, "self::M.\$c"), 1];
        yield 'site, constante absente des sources' => [$header.'throw new Problem(\\Vendor\\Messages::EMPTY);', 1];
        yield 'site, constante inconnue de la classe' => [$namespace.$siteIn($constant, 'self::N'), 1];
        yield 'site, constante en casse différente' => [$namespace.$siteIn($constant, 'self::m'), 1];
        yield 'site, constante globale' => [$header.'throw new Problem(EMPTY_MESSAGE);', 1];
        yield 'site, $x::class' => [$namespace.$siteIn('', '$x::class'), 1];
        yield 'site, appel statique' => [$messages.'throw new Problem(Messages::EMPTY());', 1];
        yield 'site, propriété statique' => [$messages.'throw new Problem(Messages::$empty);', 1];
        yield 'site, cas d\'enum' => [$namespace."enum Kind: string {\n    case Empty = 'Vide.';\n}\nthrow new Problem(Kind::Empty->value);", 1];
        yield 'site, self:: hors de toute classe' => [$header.'throw new Problem(self::M);', 1];
        yield 'constructeur, constante composée' => [$namespace."final class Problem extends \\DomainException {\n    private const string M = 'x'.\\PHP_EOL;\n"
            ."    public function __construct() { parent::__construct(self::M); }\n}\n", 1];
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
     * Deux déclarations du même nom : les sites `new` respectent le
     * constructeur de chacune, pas celui de la dernière lue (seconde
     * relecture de #514).
     */
    public function testEveryDeclarationConstrainsItsConstructionSites(): void
    {
        $class = "<?php\nnamespace App\\Y;\nfinal class Problem extends \\DomainException {\n";
        $sources = [
            'a.php' => $class."    public function __construct(string \$m) { parent::__construct(\$m); }\n}\n",
            'b.php' => $class."    public function __construct(?\\Throwable \$p = null) { parent::__construct('Vide.'); }\n}\n",
            'c.php' => "<?php\nnamespace App\\Y;\nfinal class Thrower { public function f(string \$c): never { throw new Problem('x'.\$c); } }\n",
        ];

        self::assertSame(['c.php:3 : new App\Y\Problem avec un message non littéral'], $this->violations($sources, ['App\Y\Problem']));
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
        $byName = $this->byName($this->sourceModel());
        $sources = (string) realpath(self::SOURCES);
        $resolved = 0;
        foreach (DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class) as $problem) {
            $class = new ReflectionClass($problem);
            self::assertArrayHasKey(strtolower($problem), $byName, $problem.' : chargée par PHP, le modèle ne la déclare pas.');
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
     * Le modèle de src/, lu une fois par processus : les deux tests qui le
     * lisent ne le reconstruisent pas, src/ ne change pas pendant PHPUnit.
     *
     * @return array<string, array{declarations: array<string, Declaration>, constructions: list<Construction>}>
     */
    private function sourceModel(): array
    {
        return self::$sourceModel ??= $this->parseAll($this->sourceFiles());
    }

    /**
     * @param array<string, string> $sources
     * @param list<string>          $problems
     *
     * @return list<string>
     */
    private function violations(array $sources, array $problems): array
    {
        return $this->violationsIn($this->parseAll($sources), $problems);
    }

    /**
     * @param array<string, array{declarations: array<string, Declaration>, constructions: list<Construction>}> $parsed
     * @param list<string>                                                                                        $problems
     *
     * @return list<string>
     */
    private function violationsIn(array $parsed, array $problems): array
    {
        // Les noms se comparent en minuscules (PHP résout les classes sans
        // tenir compte de la casse) et se rapportent sous leur nom déclaré.
        $declaredNames = array_combine(array_map(strtolower(...), $problems), $problems);
        $byName = $this->byName($parsed);

        // classe jugée => paramètres de son constructeur qui deviennent son
        // message, ceux que ses sites `new` doivent passer littéraux
        $messageParameters = [];
        // classe jugée => sorte de `new` => déclarations où ce `new` la construit
        $builtFrom = [];
        $violations = [];
        foreach ($parsed as $path => $file) {
            foreach (array_intersect_key($file['declarations'], $declaredNames) as $name => $declaration) {
                $judged = $this->judgedClass($declaration, $byName, $path, $declaredNames[$name]);
                $violations = [...$violations, ...$judged['violations']];
                // Deux déclarations du même nom : leurs sites sont jugés pour chacune.
                $messageParameters[$name] = array_values(array_unique([...$messageParameters[$name] ?? [], ...$judged['messageParameters']], \SORT_REGULAR));
                foreach ($this->constructionSources($declaration, $byName) as $kind => $writtenIn) {
                    $builtFrom[$name][$kind] = [...$builtFrom[$name][$kind] ?? [], ...$writtenIn];
                }
            }
        }

        return [...$violations, ...$this->constructionViolations($parsed, $byName, $declaredNames, $messageParameters, $builtFrom)];
    }

    /**
     * Ce qu'une classe jugée viole d'elle-même, et les paramètres de son
     * constructeur que ses sites `new` doivent passer littéraux.
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     *
     * @return array{violations: list<string>, messageParameters: list<array{int, string}>}
     */
    private function judgedClass(array $declaration, array $byName, string $path, string $class): array
    {
        $violations = [];
        $methods = $this->methodsOf($declaration, $byName);
        $detail = $methods['getdetail'] ?? null;
        if (null !== $detail && !$detail['returnsMessage']) {
            $violations[] = $path.' : le getDetail() de '.$class.' ne rend pas son message';
        }
        if ($this->assignsMessage($declaration, $byName)) {
            $violations[] = $path.' : '.$class.' affecte $this->message';
        }

        $constructor = $methods['__construct'] ?? null;
        if (null === $constructor) {
            return ['violations' => $violations, 'messageParameters' => self::NATIVE_MESSAGE];
        }
        // Un constructeur qui compose, ou dont le message vient d'ailleurs,
        // le fait quels que soient ses sites : ils ne sont plus jugés.
        $source = $this->messageSource($constructor, $byName);
        if (false === $source) {
            $violations[] = $path.' : le constructeur de '.$class.' tire son message d\'autre chose que de littéraux et de ses paramètres';
        } elseif (\in_array(true, $source, true)) {
            $violations[] = $path.' : le constructeur de '.$class.' compose son message';
        }

        return [
            'violations' => $violations,
            'messageParameters' => false === $source || \in_array(true, $source, true) ? [] : $this->positioned($constructor['parameters'], array_keys($source)),
        ];
    }

    /**
     * Les `new` d'une classe jugée dont un argument qui devient son message
     * n'est pas littéral.
     *
     * @param array<string, array{declarations: array<string, Declaration>, constructions: list<Construction>}> $parsed
     * @param array<string, Declaration>                                                                          $byName
     * @param array<string, string>                                                                               $declaredNames
     * @param array<string, list<array{int, string}>>                                                             $messageParameters
     * @param array<string, array<string, list<string>>>                                                          $builtFrom
     *
     * @return list<string>
     */
    private function constructionViolations(array $parsed, array $byName, array $declaredNames, array $messageParameters, array $builtFrom): array
    {
        $violations = [];
        foreach ($parsed as $path => $file) {
            foreach ($file['constructions'] as [$kind, $writtenIn, $arguments, $line, $scope]) {
                // Un nom désigne la classe jugée même déclarée hors des sources lues.
                $targets = self::BY_NAME === $kind
                    ? (isset($declaredNames[$writtenIn]) ? [$writtenIn] : [])
                    : array_keys(array_filter($builtFrom, static fn (array $sourcesByKind): bool => \in_array($writtenIn, $sourcesByKind[$kind] ?? [], true)));
                foreach ($targets as $target) {
                    if (!$this->argumentsAreLiteral($arguments, $messageParameters[$target] ?? self::NATIVE_MESSAGE, $scope, $byName)) {
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
     * en minuscules, chacune avec son `context` : de haut en bas de sa
     * lignée ({@see self::ancestry()}), chaque niveau remplace ce qu'il
     * hérite par les méthodes de ses traits, puis par les siennes — l'ordre
     * de PHP, où une méthode de trait remplace celle héritée et cède devant
     * celle de la classe. Un parent ou un trait absent des sources (vendor,
     * classe native) n'est pas lu.
     *
     * @param Declaration                $declaration
     * @param array<string, Declaration> $byName
     * @param list<string>               $seen        traits déjà lus, contre un cycle
     *
     * @return array<string, Method>
     */
    private function methodsOf(array $declaration, array $byName, array $seen = []): array
    {
        $methods = [];
        foreach (array_reverse($this->ancestry($declaration, $byName)) as $level) {
            $own = $level['methods'] + $this->fromTraits($level, $byName, [...$seen, $level['name']]);
            $methods = array_map(static fn (array $method): array => [...$method, 'context' => $level['name']], $own) + $methods;
        }

        return $methods;
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
            if (null !== $chosen && !isset($byName[$chosen])) {
                // PHP retient la méthode d'un trait que le modèle ne lit pas.
                continue;
            }
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
     * De quels paramètres de ce constructeur, retenu avec son `context`,
     * dépend le message que reçoit {@see \Exception} — et si chacun y arrive
     * composé (true) ou transmis tel quel (false) ; false si le message
     * dépend d'autre chose. Chaque `parent::__construct` passe ce qui
     * deviendra le message : le premier argument (ou `message:`) si le parent
     * est natif ou hors des sources, sinon les arguments liés aux paramètres
     * dont dépend le constructeur du parent, lu de même. Un argument littéral
     * (une constante au littéral comprise, lue depuis le `context`) n'y
     * ajoute rien, quoi qu'en fasse le parent ; une variable seule le
     * transmet ; une concaténation de littéraux et de paramètres le compose ;
     * toute autre expression, une variable locale ou un paramètre réaffecté
     * le rendent inconnu.
     *
     * @param Method                     $constructor
     * @param array<string, Declaration> $byName
     * @param list<string>               $seen        contextes déjà lus, contre un cycle
     *
     * @return MessageSource
     */
    private function messageSource(array $constructor, array $byName, array $seen = []): false|array
    {
        $context = $constructor['context'] ?? '';
        if (\in_array($context, $seen, true)) {
            return false;
        }
        $parent = $byName[$context]['parent'] ?? null;
        $parentConstructor = null !== $parent && isset($byName[$parent]) ? $this->methodsOf($byName[$parent], $byName)['__construct'] ?? null : null;
        // [position, nom, composé plus haut] des paramètres du parent qui font le message
        $messageParameters = [[0, 'message', false]];
        if (null !== $parentConstructor) {
            $source = $this->messageSource($parentConstructor, $byName, [...$seen, $context]);
            if (false === $source) {
                return false;
            }
            $messageParameters = array_map(
                static fn (array $parameter): array => [...$parameter, $source[$parameter[1]]],
                $this->positioned($parentConstructor['parameters'], array_keys($source)),
            );
        }

        $dependencies = [];
        foreach ($constructor['parentCalls'] as $arguments) {
            foreach ($messageParameters as [$position, $name, $composedAbove]) {
                $argument = $this->boundArgument($arguments, $position, $name);
                if (null === $argument || $this->isLiteral($argument, $context, $byName)) {
                    continue;
                }
                $variables = $this->parametersComposing($argument, $constructor);
                if (null === $variables) {
                    return false;
                }
                // Transmis tel quel : une seule variable, entre parenthèses au besoin.
                $composed = $composedAbove || 1 !== \count($variables)
                    || !array_all($argument, static fn (PhpToken $token): bool => $token->is([\T_VARIABLE, '(', ')']));
                foreach ($variables as $variable) {
                    $dependencies[$variable] = ($dependencies[$variable] ?? false) || $composed;
                }
            }
        }

        return $dependencies;
    }

    /**
     * Les paramètres du constructeur dont cet argument est fait, s'il n'est
     * fait que de littéraux, de `.`, de parenthèses et de paramètres jamais
     * réaffectés ; null sinon (variable locale, appel, propriété…).
     *
     * @param list<PhpToken> $argument
     * @param Method         $constructor
     *
     * @return list<string>|null
     */
    private function parametersComposing(array $argument, array $constructor): ?array
    {
        $variables = [];
        foreach ($argument as $token) {
            if ($token->is(\T_VARIABLE)) {
                $variable = substr($token->text, 1);
                if (!\in_array($variable, $constructor['parameters'], true) || \in_array($variable, $constructor['reassigned'], true)) {
                    return null;
                }
                $variables[] = $variable;
            } elseif (!$token->is([\T_CONSTANT_ENCAPSED_STRING, '.', '(', ')', ...self::LITERAL_HEREDOC_TOKENS])) {
                return null;
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * Ces paramètres, avec leur position dans la signature.
     *
     * @param list<string> $parameters la signature
     * @param list<string> $names      certains de ses paramètres
     *
     * @return list<array{int, string}>
     */
    private function positioned(array $parameters, array $names): array
    {
        return array_map(static fn (string $name): array => [(int) array_search($name, $parameters, true), $name], $names);
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
        $constants = [];
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
                $method = $this->methodAt($tokens, $index, $path, $namespace, $imports);
                if (null !== $method) {
                    $methods[$current][$this->functionName($tokens, $index)] = $method;
                }
            } elseif ($token->is(\T_CONST) && null !== $current && $depth === $bodyDepth) {
                $constants[$current] = [...$constants[$current] ?? [], ...$this->constantsAt($tokens, $index)];
            } elseif ($token->is(\T_NEW) && $this->tokenAt($tokens, $index + 1, self::NAME_TOKENS) && $this->tokenAt($tokens, $index + 2, '(')) {
                [$kind, $class] = $this->instantiated($tokens[$index + 1], $namespace, $imports, $current);
                $constructions[] = [$kind, $class, $this->arguments($tokens, $index + 2, $namespace, $imports), $token->line, $current];
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
                'constants' => $constants[$name] ?? [],
            ];
        }

        return ['declarations' => $declarations, 'constructions' => $constructions];
    }

    /**
     * La méthode concrète déclarée en $function ; null pour une méthode
     * abstraite, sans corps.
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     *
     * @return Method|null
     */
    private function methodAt(array $tokens, int $function, string $path, string $namespace, array $imports): ?array
    {
        $body = $this->bodyOf($tokens, $function);
        if (null === $body) {
            return null;
        }
        $parameters = $this->parametersOf($tokens, $function);

        return [
            'path' => $path,
            'line' => $tokens[$function]->line,
            'returnsMessage' => $this->returnsTheMessage($tokens, $body),
            'parameters' => $parameters,
            'parentCalls' => $this->parentCalls($tokens, $body, $namespace, $imports),
            'reassigned' => $this->reassignedIn($tokens, $body, $parameters),
        ];
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
     * sur les virgules de premier niveau. Le nom de classe qui précède un
     * `::` y est résolu dès la lecture, en nom complet (T_NAME_FULLY_QUALIFIED) :
     * les imports qui le résolvent sont ceux du fichier, que le jugement
     * ({@see self::namesALiteralConstant()}) n'a plus. `self`, `static` et
     * `parent` restent tels quels.
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     *
     * @return list<list<PhpToken>>
     */
    private function arguments(array $tokens, int $open, string $namespace, array $imports): array
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
            } elseif ($token->is(ClassNameResolver::NAMES) && $this->tokenAt($tokens, $index + 1, \T_DOUBLE_COLON)
                && !\in_array(strtolower($token->text), self::RELATIVE_NAMES, true)) {
                $token = new PhpToken(\T_NAME_FULLY_QUALIFIED, '\\'.ClassNameResolver::resolve($token, $namespace, $imports), $token->line, $token->pos);
            }
            $arguments[array_key_last($arguments)][] = $token;
        }

        return array_values(array_filter($arguments, static fn (array $argument): bool => [] !== $argument));
    }

    /**
     * Les arguments liés à ces paramètres ([position, nom]) sont-ils tous
     * littéraux, lus depuis la déclaration $scope où s'écrit l'appel ? Un
     * argument absent laisse la valeur par défaut du paramètre, une
     * constante : littéral.
     *
     * @param list<list<PhpToken>>       $arguments
     * @param list<array{int, string}>   $parameters
     * @param array<string, Declaration> $byName
     */
    private function argumentsAreLiteral(array $arguments, array $parameters, ?string $scope, array $byName): bool
    {
        return array_all($parameters, function (array $parameter) use ($arguments, $scope, $byName): bool {
            $argument = $this->boundArgument($arguments, $parameter[0], $parameter[1]);

            return null === $argument || $this->isLiteral($argument, $scope, $byName);
        });
    }

    /**
     * L'argument lié au paramètre de cette position et de ce nom : l'argument
     * nommé de ce nom, le positionnel de ce rang (les positionnels
     * précèdent les nommés), ou une décompression qui le précède, jamais
     * littérale ; null s'il est absent.
     *
     * @param list<list<PhpToken>> $arguments
     *
     * @return list<PhpToken>|null
     */
    private function boundArgument(array $arguments, int $position, string $name): ?array
    {
        foreach ($arguments as $index => $argument) {
            $named = $this->tokenAt($argument, 1, ':') && $argument[0]->is(\T_STRING);
            if ($named && $name === $argument[0]->text) {
                return \array_slice($argument, 2);
            }
            // Une décompression (`...$a`) peut fournir ce paramètre et les suivants.
            if (!$named && ($index === $position || $argument[0]->is(\T_ELLIPSIS))) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * Cet argument, écrit dans la déclaration $scope (null hors de toute
     * déclaration), est-il littéral : une chaîne littérale, un heredoc sans
     * variable, ou une constante de classe dont la valeur en est une (issue
     * #523) ?
     *
     * @param list<PhpToken>             $tokens
     * @param array<string, Declaration> $byName
     */
    private function isLiteral(array $tokens, ?string $scope, array $byName): bool
    {
        return $this->isStringLiteral($tokens) || $this->namesALiteralConstant($tokens, $scope, $byName);
    }

    /**
     * Ces jetons sont-ils une chaîne littérale, ou un heredoc sans variable ?
     *
     * @param list<PhpToken> $tokens
     */
    private function isStringLiteral(array $tokens): bool
    {
        if (1 === \count($tokens)) {
            return $tokens[0]->is(\T_CONSTANT_ENCAPSED_STRING);
        }

        return [] !== $tokens && array_all($tokens, static fn (PhpToken $token): bool => $token->is(self::LITERAL_HEREDOC_TOKENS));
    }

    /**
     * Ces jetons sont-ils exactement `Classe::CONSTANTE`, `self::`,
     * `parent::` ou `static::CONSTANTE`, dont la valeur est une chaîne
     * littérale ({@see self::constantIsLiteral()}) ? `self` et `parent` se
     * rapportent à la déclaration $scope ; `static`, à elle et à chacune de
     * ses sous-classes, puisque la liaison statique tardive lit la constante
     * de la classe appelée : il faut qu'aucune ne la redéfinisse autrement.
     * Un nom de classe arrive résolu ({@see self::arguments()}). Ni un appel
     * (`Classe::m()`, trois jetons de plus), ni une propriété (`Classe::$p`,
     * une variable), ni `$x::class`, ni `Enum::Cas->value` n'ont cette forme.
     *
     * @param list<PhpToken>             $tokens
     * @param array<string, Declaration> $byName
     */
    private function namesALiteralConstant(array $tokens, ?string $scope, array $byName): bool
    {
        if (3 !== \count($tokens) || !$tokens[1]->is(\T_DOUBLE_COLON) || !$this->isConstantName($tokens[2])) {
            return false;
        }
        [$class, , $constant] = $tokens;
        if ($class->is(\T_NAME_FULLY_QUALIFIED)) {
            return $this->constantIsLiteral(ltrim(strtolower($class->text), '\\'), $constant->text, $byName);
        }
        $declaration = null !== $scope ? $byName[$scope] ?? null : null;
        if (null === $declaration) {
            return false;
        }

        return match (strtolower($class->text)) {
            'self' => $this->constantIsLiteral($declaration['name'], $constant->text, $byName),
            'parent' => null !== $declaration['parent'] && $this->constantIsLiteral($declaration['parent'], $constant->text, $byName),
            'static' => array_all(
                array_filter($byName, fn (array $called): bool => \in_array($declaration['name'], array_column($this->ancestry($called, $byName), 'name'), true)),
                fn (array $called): bool => $this->constantIsLiteral($called['name'], $constant->text, $byName),
            ),
            default => false,
        };
    }

    /**
     * Ce jeton, écrit après `::`, nomme-t-il une constante ? Le lexeur rend
     * un nom semi-réservé dans son propre jeton (`Foo::EMPTY` en T_EMPTY,
     * `Foo::LIST` en T_LIST) : c'est donc son texte qui compte, un
     * identifiant, hors `class` (`Foo::class` est un nom de classe, pas un
     * message).
     */
    private function isConstantName(PhpToken $token): bool
    {
        return 1 === preg_match('/\A[a-z_\x80-\xff][a-z0-9_\x80-\xff]*\z/i', $token->text) && 'class' !== strtolower($token->text);
    }

    /**
     * La constante de ce nom (casse comprise, comme PHP), telle que la
     * classe la tient — d'elle-même ou de ses traits, sinon de ses parents
     * et de leurs traits —, a-t-elle pour valeur une chaîne littérale ? Une
     * classe ou une constante absente des sources lues ne l'a pas.
     *
     * @param array<string, Declaration> $byName
     */
    private function constantIsLiteral(string $class, string $constant, array $byName): bool
    {
        if (!isset($byName[$class])) {
            return false;
        }
        foreach ($this->ancestry($byName[$class], $byName) as $level) {
            $traits = array_map(static fn (string $trait): array => $byName[$trait], $this->traitsOf($level, $byName));
            foreach ([$level, ...$traits] as $declared) {
                if (isset($declared['constants'][$constant])) {
                    return $declared['constants'][$constant];
                }
            }
        }

        return false;
    }

    /**
     * Les constantes du `const` en $const (`const A = …, B = …;`, typées
     * ou non) : nom tel qu'écrit => sa valeur est-elle une chaîne littérale
     * ({@see self::isStringLiteral()}) ? Une valeur composée, ou faite d'une
     * autre constante, ne l'est pas : refusée par prudence plutôt que suivie.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array<string, bool>
     */
    private function constantsAt(array $tokens, int $const): array
    {
        $constants = [];
        $name = null;
        $value = [];
        $level = 0;
        for ($index = $const + 1, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (0 === $level && $token->is([',', ';'])) {
                if (null !== $name) {
                    $constants[$name] = $this->isStringLiteral($value);
                }
                if ($token->is(';')) {
                    break;
                }
                [$name, $value] = [null, []];

                continue;
            }
            if ($token->is(['(', '['])) {
                ++$level;
            } elseif ($token->is([')', ']'])) {
                --$level;
            }
            if (null !== $name) {
                $value[] = $token;
            } elseif ($token->is('=')) {
                // Le nom précède `=`, après le type éventuel (`const string M = …`).
                $name = $tokens[$index - 1]->text;
            }
        }

        return $constants;
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
     * Les arguments de chaque `parent::__construct(…)` du corps qui s'ouvre
     * en $body (un appel par branche, par exemple) — ou `Nom::__construct(…)`,
     * le constructeur d'un ancêtre appelé par son nom, lu comme celui du
     * parent. `{$x}` et `${x}` ouvrent
     * une accolade comme `{` ({@see PhpSources::opensBrace()}, issue #507).
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     *
     * @return list<list<list<PhpToken>>>
     */
    private function parentCalls(array $tokens, int $body, string $namespace, array $imports): array
    {
        $calls = [];
        $depth = 0;
        for ($index = $body, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (PhpSources::opensBrace($token)) {
                ++$depth;
            } elseif ($token->is('}') && 0 === --$depth) {
                break;
            } elseif ($depth > 0 && $token->is(ClassNameResolver::NAMES) && !\in_array(strtolower($token->text), ['self', 'static'], true)
                && $this->tokenAt($tokens, $index + 1, \T_DOUBLE_COLON)
                && '__construct' === strtolower($tokens[$index + 2]->text ?? '')) {
                $calls[] = $this->arguments($tokens, $index + 3, $namespace, $imports);
            }
        }

        return $calls;
    }

    /**
     * Ceux de ces paramètres que le corps qui s'ouvre en $body réaffecte,
     * noms sans `$` : par une affectation (`$x =`, `$x .=`…), une référence
     * (`&$x`), un `foreach (… as …)`, une déstructuration (`[$x] =`,
     * `list($x) =`), un `catch`, `global` ou `static` ; tous, devant
     * `extract()`, `parse_str()` ou une variable variable (`$$x`, `${…}`). Un
     * paramètre réaffecté n'est plus transmis tel quel. Non vu : un passage
     * par référence à une fonction (`preg_match($p, $s, $x)`).
     *
     * @param list<PhpToken> $tokens
     * @param list<string>   $parameters
     *
     * @return list<string>
     */
    private function reassignedIn(array $tokens, int $body, array $parameters): array
    {
        $reassigned = [];
        $depth = 0;
        for ($index = $body, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (PhpSources::opensBrace($token)) {
                ++$depth;
            } elseif ($token->is('}') && 0 === --$depth) {
                break;
            }
            if ($token->is([\T_AS, \T_CATCH])) {
                // Jusqu'à la parenthèse qui ferme l'en-tête du foreach ou du catch.
                $reassigned = [...$reassigned, ...$this->variablesUntilClosingParenthesis($tokens, $index + 1)];
            } elseif ($token->is('=') && $this->tokenAt($tokens, $index - 1, [']', ')'])) {
                $reassigned = [...$reassigned, ...$this->destructuredBefore($tokens, $index - 1)];
            } elseif ($token->is('$') || $token->is(\T_DOLLAR_OPEN_CURLY_BRACES)
                || ($token->is(\T_STRING) && \in_array(strtolower($token->text), ['extract', 'parse_str'], true) && $this->tokenAt($tokens, $index + 1, '('))) {
                return $parameters;
            } elseif ($token->is(\T_VARIABLE)
                && ($this->tokenAt($tokens, $index + 1, self::VARIABLE_ASSIGNMENTS) || $this->tokenAt($tokens, $index - 1, ['&', \T_GLOBAL, \T_STATIC]))) {
                $reassigned[] = substr($token->text, 1);
            }
        }

        return array_values(array_intersect($parameters, $reassigned));
    }

    /**
     * Les variables nommées depuis $index jusqu'à la parenthèse fermante qui
     * n'a pas été ouverte entre-temps, noms sans `$`.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<string>
     */
    private function variablesUntilClosingParenthesis(array $tokens, int $index): array
    {
        $variables = [];
        $level = 0;
        for ($count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token->is('(')) {
                ++$level;
            } elseif ($token->is(')') && 0 === $level--) {
                break;
            } elseif ($token->is(\T_VARIABLE)) {
                $variables[] = substr($token->text, 1);
            }
        }

        return $variables;
    }

    /**
     * Les variables de la déstructuration qui se ferme en $close (`]` ou la
     * `)` d'un `list(`), noms sans `$` ; aucune si ce n'en est pas une.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<string>
     */
    private function destructuredBefore(array $tokens, int $close): array
    {
        [$opener, $closer] = $tokens[$close]->is(']') ? ['[', ']'] : ['(', ')'];
        $variables = [];
        $level = 0;
        for ($index = $close; $index >= 0; --$index) {
            $token = $tokens[$index];
            if ($token->is($closer)) {
                ++$level;
            } elseif ($token->is($opener) && 0 === --$level) {
                $isDestructuring = '[' === $opener ? !$this->tokenAt($tokens, $index - 1, [\T_VARIABLE, ']', ')', \T_STRING]) : $this->tokenAt($tokens, $index - 1, \T_LIST);

                return $isDestructuring ? $variables : [];
            } elseif ($token->is(\T_VARIABLE)) {
                $variables[] = substr($token->text, 1);
            }
        }

        return [];
    }

    /**
     * Les noms, sans `$`, des paramètres de la fonction déclarée en
     * $function, dans l'ordre : les variables au premier niveau de ses
     * parenthèses, hors attributs et crochets de propriété d'un paramètre
     * promu.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<string>
     */
    private function parametersOf(array $tokens, int $function): array
    {
        $parameters = [];
        $level = 0;
        for ($index = $function + 1, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token->is(['(', '[', \T_ATTRIBUTE]) || PhpSources::opensBrace($token)) {
                ++$level;
            } elseif ($token->is([')', ']', '}']) && 0 === --$level) {
                break;
            } elseif (1 === $level && $token->is(\T_VARIABLE)) {
                $parameters[] = substr($token->text, 1);
            }
        }

        return $parameters;
    }
}
