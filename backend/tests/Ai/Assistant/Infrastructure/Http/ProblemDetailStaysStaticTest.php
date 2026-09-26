<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AssistantProblemResponseListener renvoie au client le `detail` (le message)
 * de toute ProblemExceptionInterface levée sous /api/assistant. Un message
 * construit avec ce que le client a envoyé (une borne D6 qui citerait le
 * message trop long, par exemple) le renverrait tel quel. Garde-fou statique,
 * sur toutes les ProblemExceptionInterface de src/ :
 *
 *  - aucune ne surcharge getDetail() ;
 *  - une classe qui déclare un constructeur passe un message littéral à
 *    `parent::__construct` — elle fixe alors son message, et ses sites de
 *    construction importent peu (une cause en `previous`, par exemple) ;
 *  - pour les autres, chaque `new` (y compris `new self`/`new static`, alias
 *    `use … as` résolus, dans n'importe quel contexte syntaxique) ne reçoit
 *    comme message qu'une chaîne littérale, un heredoc sans variable ou rien.
 *
 * Une exception au message dynamique légitime ailleurs (rendue par API
 * Platform sur une autre route) s'inscrit dans DYNAMIC_DETAIL_ALLOWED, avec sa
 * justification : c'est la seule façon d'échapper au garde-fou, et elle se voit.
 *
 * Analyse par jetons (PhpToken), pas par expression régulière : commentaires,
 * `;` dans une chaîne et arguments nommés ne le trompent pas. Ce qu'il ne voit
 * pas, et c'est assumé : une classe instanciée dynamiquement (`new $classe`,
 * Reflection). Le garde-fou vise l'oubli, pas la malveillance.
 */
final class ProblemDetailStaysStaticTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../../src';

    /**
     * Messages dynamiques admis, parce qu'aucune de ces exceptions ne peut être
     * levée sous /api/assistant : elles appartiennent à des routes de
     * backoffice (ROLE_SUPER), rendues par API Platform, et citent une donnée
     * que leur appelant connaît déjà.
     */
    private const array DYNAMIC_DETAIL_ALLOWED = [
        'App\Security\User\Domain\Exception\CannotModifyOwnRolesException' => 'PUT /api/backoffice/users/{id}/roles : cite le username de l\'appelant.',
        'App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException' => 'PUT /api/backoffice/users/{id}/roles : cite le username visé.',
        'App\Portfolio\Shared\Domain\Exception\TranslationAlreadyExistsException' => 'Écritures du backoffice : cite le groupe et la locale envoyés.',
        'App\Portfolio\Shared\Domain\Exception\UnknownTranslationGroupException' => 'Écritures du backoffice : cite le groupe envoyé.',
        'App\Portfolio\Shared\Domain\Exception\IncompleteOrderException' => 'PUT /api/backoffice/<x>/order : cite les clés manquantes.',
        'App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException' => 'PUT /api/backoffice/<x>/order : cite la clé inconnue.',
    ];

    private const array IGNORED = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];
    private const array NAME_TOKENS = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_STATIC];
    private const array LITERAL_HEREDOC_TOKENS = [\T_START_HEREDOC, \T_ENCAPSED_AND_WHITESPACE, \T_END_HEREDOC];

    public function testEveryProblemDetailInSourcesIsALiteral(): void
    {
        $sources = iterator_to_array($this->sourceFiles());
        $problems = $this->problemClasses($sources);
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

        $class = "<?php\nnamespace App\\Y;\nfinal class Problem extends \\DomainException {\n";
        yield 'fabrique new self' => [$class."    public static function tooLong(string \$c): self { return new self('Trop long : '.\$c); }\n}", 1];
        yield 'fabrique new static' => [$class."    public static function tooLong(string \$c): static { return new static(\$c); }\n}", 1];
        yield 'getDetail surchargé' => [$class."    public function getDetail(): string { return \$this->c; }\n}", 1];
        yield 'constructeur qui formate' => [$class."    public function __construct(string \$c) { parent::__construct('Trop long : '.\$c); }\n}", 1];
        yield 'constructeur au message fixe' => [$class."    public function __construct(?\\Throwable \$p = null) { parent::__construct('Vide.', 0, \$p); }\n}\n"
            ."function f(\\Throwable \$e) { throw new Problem(\$e); }", 0];
    }

    /** Le détecteur lui-même : sans cette preuve, il pourrait être vert pour de mauvaises raisons. */
    #[DataProvider('snippets')]
    public function testTheDetector(string $code, int $expectedViolations): void
    {
        self::assertCount($expectedViolations, $this->violations(['extrait.php' => $code], ['App\Y\Problem']));
    }

    /**
     * @return iterable<string, string>
     */
    private function sourceFiles(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCES, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                yield $file->getPathname() => (string) file_get_contents($file->getPathname());
            }
        }
    }

    /**
     * @param array<string, string> $sources
     *
     * @return list<string> FQCN des ProblemExceptionInterface déclarées dans src/
     */
    private function problemClasses(array $sources): array
    {
        $problems = [];
        foreach ($sources as $code) {
            $file = $this->parse($code);
            foreach ($file['classes'] as $class) {
                if (class_exists($class) && is_subclass_of($class, ProblemExceptionInterface::class)) {
                    $problems[] = $class;
                }
            }
        }

        return $problems;
    }

    /**
     * @param array<string, string> $sources
     * @param list<string>          $problems
     *
     * @return list<string>
     */
    private function violations(array $sources, array $problems): array
    {
        $parsed = array_map($this->parse(...), $sources);

        $fixesItsMessage = [];
        $violations = [];
        foreach ($parsed as $path => $file) {
            foreach ($file['declarations'] as $class => $declaration) {
                if (!\in_array($class, $problems, true)) {
                    continue;
                }
                if ($declaration['overridesDetail']) {
                    $violations[] = $path.' : '.$class.' surcharge getDetail()';
                }
                if (null !== $declaration['constructorMessageIsLiteral']) {
                    $fixesItsMessage[$class] = $declaration['constructorMessageIsLiteral'];
                    if (!$declaration['constructorMessageIsLiteral']) {
                        $violations[] = $path.' : le constructeur de '.$class.' compose son message';
                    }
                }
            }
        }

        foreach ($parsed as $path => $file) {
            foreach ($file['constructions'] as [$class, $literal, $line]) {
                if (\in_array($class, $problems, true) && !isset($fixesItsMessage[$class]) && !$literal) {
                    $violations[] = $path.':'.$line.' : new '.$class.' avec un message non littéral';
                }
            }
        }

        return $violations;
    }

    /**
     * @return array{
     *     classes: list<string>,
     *     declarations: array<string, array{overridesDetail: bool, constructorMessageIsLiteral: ?bool}>,
     *     constructions: list<array{string, bool, int}>,
     * }
     */
    private function parse(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->is(self::IGNORED),
        ));

        $namespace = '';
        $aliases = [];
        $currentClass = null;
        $depth = 0;
        $classes = [];
        $overridesDetail = [];
        $constructorMessageIsLiteral = [];
        $constructions = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
            } elseif ($token->is('}')) {
                --$depth;
            } elseif ($token->is(\T_NAMESPACE)) {
                $namespace = $tokens[$index + 1]->text ?? '';
            } elseif ($token->is(\T_USE) && 0 === $depth) {
                $this->collectAlias($tokens, $index, $aliases);
            } elseif ($token->is(\T_CLASS) && !self::tokenAt($tokens, $index - 1, [\T_NEW, \T_DOUBLE_COLON])) {
                $currentClass = ltrim($namespace.'\\'.($tokens[$index + 1]->text ?? ''), '\\');
                $classes[] = $currentClass;
            } elseif ($token->is(\T_FUNCTION) && null !== $currentClass) {
                $name = strtolower($tokens[$index + 1]->text ?? '');
                if ('getdetail' === $name) {
                    $overridesDetail[$currentClass] = true;
                } elseif ('__construct' === $name) {
                    $constructorMessageIsLiteral[$currentClass] = $this->parentMessageIsLiteral($tokens, $index);
                }
            } elseif ($token->is(\T_NEW) && self::tokenAt($tokens, $index + 1, self::NAME_TOKENS) && self::tokenAt($tokens, $index + 2, '(')) {
                $class = $this->resolve($tokens[$index + 1]->text, $namespace, $aliases, $currentClass);
                $constructions[] = [$class, $this->messageIsLiteral($this->arguments($tokens, $index + 2)), $token->line];
            }
        }

        $declarations = [];
        foreach ($classes as $class) {
            $declarations[$class] = [
                'overridesDetail' => $overridesDetail[$class] ?? false,
                'constructorMessageIsLiteral' => $constructorMessageIsLiteral[$class] ?? null,
            ];
        }

        return ['classes' => $classes, 'declarations' => $declarations, 'constructions' => $constructions];
    }

    /**
     * @param list<\PhpToken>                 $tokens
     * @param int|string|list<int|string>     $kind
     */
    private static function tokenAt(array $tokens, int $index, int|string|array $kind): bool
    {
        return isset($tokens[$index]) && $tokens[$index]->is($kind);
    }

    /**
     * @param list<\PhpToken>       $tokens
     * @param array<string, string> $aliases
     */
    private function collectAlias(array $tokens, int $index, array &$aliases): void
    {
        $name = $tokens[$index + 1] ?? null;
        if (null === $name || !$name->is([\T_NAME_QUALIFIED, \T_STRING, \T_NAME_FULLY_QUALIFIED])) {
            return;
        }

        $fqcn = ltrim($name->text, '\\');
        $alias = self::tokenAt($tokens, $index + 2, \T_AS) ? ($tokens[$index + 3]->text ?? '') : substr((string) strrchr('\\'.$fqcn, '\\'), 1);
        $aliases[strtolower($alias)] = $fqcn;
    }

    /**
     * @param array<string, string> $aliases
     */
    private function resolve(string $name, string $namespace, array $aliases, ?string $currentClass): string
    {
        if (\in_array(strtolower($name), ['self', 'static'], true)) {
            return (string) $currentClass;
        }
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = strtolower(explode('\\', $name)[0]);
        if (isset($aliases[$first])) {
            return $aliases[$first].substr($name, \strlen($first));
        }

        return ltrim($namespace.'\\'.$name, '\\');
    }

    /**
     * Arguments d'un appel dont la parenthèse ouvrante est en $open, découpés
     * sur les virgules de premier niveau.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return list<list<\PhpToken>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $arguments = [[]];
        $level = 0;
        for ($index = $open + 1, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token->is(['(', '[', '{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
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
     * @param list<list<\PhpToken>> $arguments
     */
    private function messageIsLiteral(array $arguments): bool
    {
        foreach ($arguments as $position => $argument) {
            $named = self::tokenAt($argument, 1, ':') && $argument[0]->is(\T_STRING);
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
     * @param list<\PhpToken> $tokens
     */
    private function isLiteral(array $tokens): bool
    {
        if (1 === \count($tokens)) {
            return $tokens[0]->is(\T_CONSTANT_ENCAPSED_STRING);
        }

        return [] !== $tokens && array_all($tokens, static fn (\PhpToken $token): bool => $token->is(self::LITERAL_HEREDOC_TOKENS));
    }

    /**
     * Dans le constructeur qui commence en $function : le message passé à
     * `parent::__construct` est-il littéral ? Sans appel au parent, le message
     * reste celui par défaut (vide) : littéral.
     *
     * @param list<\PhpToken> $tokens
     */
    private function parentMessageIsLiteral(array $tokens, int $function): bool
    {
        $depth = 0;
        for ($index = $function, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token->is('{')) {
                ++$depth;
            } elseif ($token->is('}') && 0 === --$depth) {
                return true;
            } elseif ($depth > 0 && $token->is(\T_STRING) && 'parent' === strtolower($token->text)
                && self::tokenAt($tokens, $index + 1, \T_DOUBLE_COLON)
                && '__construct' === strtolower($tokens[$index + 2]->text ?? '')) {
                return $this->messageIsLiteral($this->arguments($tokens, $index + 3));
            }
        }

        return true;
    }
}
