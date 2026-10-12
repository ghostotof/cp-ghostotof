<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
use App\Portfolio\Shared\Domain\Exception\TranslationAlreadyExistsException;
use App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException;
use App\Portfolio\Shared\Domain\Exception\UnknownTranslationGroupException;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Tests\Support\ClassNameResolver;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\PhpSources;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
 *  - une classe qui a un constructeur passe un message littéral à
 *    `parent::__construct` — elle fixe alors son message, et ses sites de
 *    construction importent peu (une cause en `previous`, par exemple) ;
 *  - pour les autres, chaque `new` (y compris `new self`/`new static`, alias
 *    `use … as` résolus, dans n'importe quel contexte syntaxique) ne reçoit
 *    comme message qu'une chaîne littérale, un heredoc sans variable ou rien.
 *
 * Une méthode d'un trait est celle de la classe qui l'utilise, directement ou
 * par un autre trait, à moins que la classe ne déclare la sienne ; une méthode
 * abstraite ne compte pas. Les noms se résolvent comme PHP le fait — casse
 * indifférente, listes `use A, B;`, `namespace\X` — par
 * {@see ClassNameResolver}, partagé avec NoBareGenericExceptionTest (issue
 * #507).
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
 *  - `new parent(…)`, et ce qu'une classe hérite d'un parent qui n'est pas
 *    lui-même une ProblemExceptionInterface (son getDetail(), son
 *    constructeur) : seuls la classe et ses traits sont lus (suivi #514) ;
 *  - les adaptations de trait (`use A, B { A::getDetail insteadof B; x as
 *    getDetail; }`) : entre deux traits, le premier nommé l'emporte. Aucun
 *    trait de src/ n'en a besoin, et les lire demanderait de rejouer la
 *    résolution des conflits de PHP pour un cas qui n'existe pas ;
 *  - l'import groupé (`use Foo\{A, B};`) : ImportsStaySortedTest le refuse
 *    déjà dans src/ et tests/ ;
 *  - une classe déclarée dans le corps d'une méthode ou d'une fonction : les
 *    fonctions hors classe sont interdites dans src/ (issue #432), et une
 *    déclaration conditionnelle n'y existe pas.
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
        CannotModifyOwnRolesException::class => 'PUT /api/backoffice/users/{id}/roles : cite le username de l\'appelant.',
        CannotDemoteLastSuperAdminException::class => 'PUT /api/backoffice/users/{id}/roles : cite le username visé.',
        TranslationAlreadyExistsException::class => 'Écritures du backoffice : cite le groupe et la locale envoyés.',
        UnknownTranslationGroupException::class => 'Écritures du backoffice : cite le groupe envoyé.',
        IncompleteOrderException::class => 'PUT /api/backoffice/<x>/order : cite les clés manquantes.',
        UnknownOrderEntryException::class => 'PUT /api/backoffice/<x>/order : cite la clé inconnue.',
    ];

    private const array NAME_TOKENS = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE, \T_STATIC];
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

        $class = "<?php\nnamespace App\\Y;\nfinal class Problem extends \\DomainException {\n";
        yield 'fabrique new self' => [$class."    public static function tooLong(string \$c): self { return new self('Trop long : '.\$c); }\n}", 1];
        yield 'fabrique new static' => [$class."    public static function tooLong(string \$c): static { return new static(\$c); }\n}", 1];
        yield 'getDetail surchargé' => [$class."    public function getDetail(): string { return \$this->c; }\n}", 1];
        yield 'constructeur qui formate' => [$class."    public function __construct(string \$c) { parent::__construct('Trop long : '.\$c); }\n}", 1];
        yield 'constructeur au message fixe' => [$class."    public function __construct(?\\Throwable \$p = null) { parent::__construct('Vide.', 0, \$p); }\n}\n"
            ."function f(\\Throwable \$e) { throw new Problem(\$e); }", 0];
        yield 'constructeur qui interpole ${x}' => [$class."    public function __construct(string \$c) { \$m = \"a \${c}\"; parent::__construct(\$m); }\n}", 1];

        // Fin de la classe (issue #507) : ce qui suit ne lui appartient plus.
        yield 'fonction après la classe' => [$class."}\nfunction getDetail(): string { return ''; }", 0];
        yield 'new self après la classe' => [$class."}\nfunction f(string \$c): void { new self('x'.\$c); }", 0];
        yield 'interface après la classe' => [$class."}\ninterface Other { public function getDetail(): string; }", 0];

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
        yield 'use d\'une fermeture dans une méthode' => [$class."    public static function f(string \$c): \\Closure { return function () use (\$c): string { return \$c; }; }\n}", 0];
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
        $parsed = array_map($this->parse(...), $sources);

        $declarations = [];
        $declaredIn = [];
        foreach ($parsed as $path => $file) {
            foreach ($file['declarations'] as $name => $declaration) {
                $declarations[$name] = $declaration;
                $declaredIn[$name] = $path;
            }
        }

        $fixesItsMessage = [];
        $violations = [];
        foreach ($declaredNames as $name => $class) {
            if (!isset($declarations[$name])) {
                continue;
            }
            $declaration = $this->withTraits($name, $declarations);
            if (false === $declaration['detailIsMessage']) {
                $violations[] = $declaredIn[$name].' : le getDetail() de '.$class.' ne rend pas son message';
            }
            if (null !== $declaration['constructorMessageIsLiteral']) {
                $fixesItsMessage[$name] = true;
                if (!$declaration['constructorMessageIsLiteral']) {
                    $violations[] = $declaredIn[$name].' : le constructeur de '.$class.' compose son message';
                }
            }
        }

        foreach ($parsed as $path => $file) {
            foreach ($file['constructions'] as [$name, $literal, $line]) {
                if (isset($declaredNames[$name]) && !isset($fixesItsMessage[$name]) && !$literal) {
                    $violations[] = $path.':'.$line.' : new '.$declaredNames[$name].' avec un message non littéral';
                }
            }
        }

        return $violations;
    }

    /**
     * Ce que la déclaration hérite des traits qu'elle utilise, directement ou
     * par un autre trait : une méthode d'un trait est celle de la classe, sauf
     * si la classe déclare la sienne, qui l'emporte. Entre deux traits qui
     * déclarent la même méthode, PHP exige un `insteadof` que ce garde-fou ne
     * lit pas : le premier nommé est retenu.
     *
     * @param array<string, array{detailIsMessage: ?bool, constructorMessageIsLiteral: ?bool, traits: list<string>}> $declarations
     * @param list<string>                                                                                     $seen         traits déjà lus, contre un cycle
     *
     * @return array{detailIsMessage: ?bool, constructorMessageIsLiteral: ?bool}
     */
    private function withTraits(string $name, array $declarations, array $seen = []): array
    {
        $declaration = $declarations[$name] ?? ['detailIsMessage' => null, 'constructorMessageIsLiteral' => null, 'traits' => []];
        $detailIsMessage = $declaration['detailIsMessage'];
        $constructorMessageIsLiteral = $declaration['constructorMessageIsLiteral'];
        foreach (array_diff($declaration['traits'], $seen, [$name]) as $trait) {
            $inherited = $this->withTraits($trait, $declarations, [...$seen, $name]);
            $detailIsMessage ??= $inherited['detailIsMessage'];
            $constructorMessageIsLiteral ??= $inherited['constructorMessageIsLiteral'];
        }

        return ['detailIsMessage' => $detailIsMessage, 'constructorMessageIsLiteral' => $constructorMessageIsLiteral];
    }

    /**
     * Les déclarations (classes, interfaces, traits, enums) et les `new` d'un
     * fichier, noms résolus en minuscules par {@see ClassNameResolver}.
     *
     * @return array{
     *     declarations: array<string, array{detailIsMessage: ?bool, constructorMessageIsLiteral: ?bool, traits: list<string>}>,
     *     constructions: list<array{string, bool, int}>,
     * }
     */
    private function parse(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $namespace = '';
        $imports = [];
        // La déclaration en cours et la profondeur de son corps : ce qui
        // s'écrit après sa `}` fermante ne lui appartient plus.
        $current = null;
        $bodyDepth = 0;
        $depth = 0;
        $declared = [];
        $detailIsMessage = [];
        $constructorMessageIsLiteral = [];
        $traits = [];
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
            } elseif ($token->is(\T_USE) && 0 === $depth) {
                $imports = array_merge($imports, ClassNameResolver::imports($tokens, $index + 1));
            } elseif ($token->is(\T_USE) && null !== $current && $depth === $bodyDepth) {
                $traits[$current] = [...$traits[$current] ?? [], ...$this->usedTraits($tokens, $index + 1, $namespace, $imports)];
            } elseif ($token->is(PhpSources::CLASS_LIKE) && !$this->tokenAt($tokens, $index - 1, [\T_NEW, \T_DOUBLE_COLON])) {
                $current = ltrim($namespace.'\\'.strtolower($tokens[$index + 1]->text ?? ''), '\\');
                $bodyDepth = $depth + 1;
                $declared[] = $current;
            } elseif ($token->is(\T_FUNCTION) && null !== $current && $depth === $bodyDepth) {
                // Une méthode abstraite n'a pas de corps : elle ne déclare rien ici.
                $function = strtolower($tokens[$index + 1]->text ?? '');
                $body = $this->bodyOf($tokens, $index);
                if (null !== $body && 'getdetail' === $function) {
                    $detailIsMessage[$current] = $this->returnsTheMessage($tokens, $body);
                } elseif (null !== $body && '__construct' === $function) {
                    $constructorMessageIsLiteral[$current] = $this->parentMessageIsLiteral($tokens, $body);
                }
            } elseif ($token->is(\T_NEW) && $this->tokenAt($tokens, $index + 1, self::NAME_TOKENS) && $this->tokenAt($tokens, $index + 2, '(')) {
                $class = $this->instantiated($tokens[$index + 1], $namespace, $imports, $current);
                $constructions[] = [$class, $this->messageIsLiteral($this->arguments($tokens, $index + 2)), $token->line];
            }
        }

        $declarations = [];
        foreach ($declared as $name) {
            $declarations[$name] = [
                'detailIsMessage' => $detailIsMessage[$name] ?? null,
                'constructorMessageIsLiteral' => $constructorMessageIsLiteral[$name] ?? null,
                'traits' => $traits[$name] ?? [],
            ];
        }

        return ['declarations' => $declarations, 'constructions' => $constructions];
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
     * Les traits d'un `use A, B;` (ou `use A, B { … }`) écrit dans le corps
     * d'une déclaration, le premier nom en $index : des noms de classe, que
     * l'espace de noms et les imports résolvent.
     *
     * @param list<PhpToken>        $tokens
     * @param array<string, string> $imports
     *
     * @return list<string>
     */
    private function usedTraits(array $tokens, int $index, string $namespace, array $imports): array
    {
        $traits = [];
        while ($this->tokenAt($tokens, $index, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
            $traits[] = ClassNameResolver::resolve($tokens[$index], $namespace, $imports);
            if (!$this->tokenAt($tokens, $index + 1, ',')) {
                break;
            }
            $index += 2;
        }

        return $traits;
    }

    /**
     * La classe, en minuscules, que désigne le nom écrit après `new` :
     * `self`/`static` donnent la déclaration en cours (aucune hors d'une
     * déclaration), tout autre nom se résout par {@see ClassNameResolver}.
     *
     * @param array<string, string> $imports
     */
    private function instantiated(PhpToken $name, string $namespace, array $imports, ?string $current): string
    {
        if (\in_array(strtolower($name->text), ['self', 'static'], true)) {
            return (string) $current;
        }

        return ClassNameResolver::resolve($name, $namespace, $imports);
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
     * `parent::__construct` est-il littéral ? Sans appel au parent, le message
     * reste celui par défaut (vide) : littéral. `{$x}` et `${x}` ouvrent une
     * accolade comme `{` ({@see PhpSources::opensBrace()}, issue #507).
     *
     * @param list<PhpToken> $tokens
     */
    private function parentMessageIsLiteral(array $tokens, int $body): bool
    {
        $depth = 0;
        for ($index = $body, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (PhpSources::opensBrace($token)) {
                ++$depth;
            } elseif ($token->is('}') && 0 === --$depth) {
                return true;
            } elseif ($depth > 0 && $token->is(\T_STRING) && 'parent' === strtolower($token->text)
                && $this->tokenAt($tokens, $index + 1, \T_DOUBLE_COLON)
                && '__construct' === strtolower($tokens[$index + 2]->text ?? '')) {
                return $this->messageIsLiteral($this->arguments($tokens, $index + 3));
            }
        }

        return true;
    }
}
