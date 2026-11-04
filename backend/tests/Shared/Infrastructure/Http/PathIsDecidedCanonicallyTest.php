<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use App\Tests\Support\ClassNameResolver;
use App\Tests\Support\MemberCalls;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aucun fichier de `src/` ne lit le chemin brut d'une requête ni ne juge un chemin en chaîne (#748, C11-4).
 *
 * `Request::getPathInfo()` renvoie le chemin NON décodé alors que le routeur, les firewalls et l'`access_control`
 * décident sur `rawurldecode()` : un `%XX` dans l'URL suffit à faire rater une garde qui lit le brut (`POST
 * /%61pi/logout`, `/api/account/base%2Daccess`, issue #77). {@see CanonicalPath::of()} est le seul endroit qui
 * décode ; {@see CanonicalPath::isPathUnder()}, publique pour les tests qui parcourent le routeur, juge une chaîne
 * telle qu'elle vient : `isPathUnder($request->getPathInfo(), '/api')` rouvrirait le contournement sans qu'aucun
 * test d'écoute ne le voie. Hors de `CanonicalPath.php`, une requête passe donc par `isUnder()` ou `of()`.
 *
 * Refusés dans `src/` (par jetons, commentaires et chaînes exclus) : un appel `getPathInfo(` ou `isPathUnder(`,
 * sans égard à la casse (PHP n'en fait pas pour un nom de méthode), écrit `->`, `?->` ou `::`, y compris en appelable
 * de première classe (`->getPathInfo(...)`), hors de `CanonicalPath.php`. Aucun appel légitime n'existe aujourd'hui,
 * donc aucune exemption. La forme d'appel est celle de {@see MemberCalls}, que lisent aussi les recenseurs qui jugent un appel de membre.
 * Le compteur de lectures canoniques ne compte qu'un nom qui résout vers `CanonicalPath` (alias compris), jamais un
 * homonyme d'un autre espace de noms.
 *
 * Ce que la garde ne voit pas, limite assumée : un chemin lu autrement que par ces deux noms
 * (`$request->server->get('PATH_INFO')`, `getRequestUri()`, `getUri()`, `getBaseUrl()`, `$_SERVER`), un nom construit
 * à l'exécution (`$request->{'getPathInfo'}()`) et un `Request` lu par un tiers de `vendor/`.
 */
final class PathIsDecidedCanonicallyTest extends TestCase
{
    private const string CANONICAL_PATH_FILE = '/src/Shared/Infrastructure/Http/CanonicalPath.php';

    /** Les méthodes refusées, en minuscules : PHP ne distingue pas la casse d'un nom de méthode. */
    private const array FORBIDDEN_CALLS = ['getpathinfo', 'ispathunder'];

    /** Le périmètre lu : `src/` compte plusieurs centaines de fichiers, une lecture vide ne garde rien. */
    private const int MINIMUM_FILES_READ = 300;

    public function testNoFileOfSrcReadsTheRawPathOrJudgesAStringPath(): void
    {
        $found = [];
        foreach ($this->sourcesOutsideCanonicalPath() as $path) {
            foreach ($this->forbiddenCallsIn(RepositoryFiles::read($path)) as $site) {
                $found[] = $path.':'.$site['line'].' : '.$site['name'];
            }
        }

        self::assertSame(
            [],
            $found,
            'Le chemin d\'une requête se décide sur CanonicalPath::of() (décodé), jamais sur getPathInfo() '
            .'ni sur isPathUnder() d\'une chaîne : un %XX contournerait la garde (issue #77). Voir le docblock de ce test.',
        );
    }

    public function testTheGuardSeesTheCanonicalReads(): void
    {
        $files = $this->sourcesOutsideCanonicalPath();
        self::assertGreaterThanOrEqual(self::MINIMUM_FILES_READ, \count($files), 'La garde ne lit presque rien de src/.');

        $canonicalReads = 0;
        foreach ($files as $path) {
            $canonicalReads += $this->canonicalReadsIn(RepositoryFiles::read($path));
        }

        self::assertGreaterThan(0, $canonicalReads, 'Aucun CanonicalPath::of( dans src/ : la garde ne voit plus les lectures.');
    }

    /**
     * @param list<array{line: int, name: string}> $expected chaque appel refusé, dans l'ordre du code
     */
    #[DataProvider('formsTheDetectorSees')]
    public function testTheDetectorSeesEachForbiddenCall(string $code, array $expected): void
    {
        self::assertSame($expected, $this->forbiddenCallsIn($code));
    }

    /**
     * @return iterable<string, array{string, list<array{line: int, name: string}>}>
     */
    public static function formsTheDetectorSees(): iterable
    {
        yield 'lecture du chemin brut' => [
            "<?php\n\$p = \$request->getPathInfo();\n",
            [['line' => 2, 'name' => 'getPathInfo']],
        ];
        yield 'lecture nullsafe' => [
            "<?php\n\$p = \$request?->getPathInfo();\n",
            [['line' => 2, 'name' => 'getPathInfo']],
        ];
        yield 'appelable de première classe' => [
            "<?php\n\$f = \$request->getPathInfo(...);\n",
            [['line' => 2, 'name' => 'getPathInfo']],
        ];
        yield 'jugement d\'une chaîne, nom court' => [
            "<?php\nCanonicalPath::isPathUnder(\$path, '/api');\n",
            [['line' => 2, 'name' => 'isPathUnder']],
        ];
        yield 'jugement d\'une chaîne, nom complet' => [
            "<?php\n\\App\\Shared\\Infrastructure\\Http\\CanonicalPath::isPathUnder(\$path, '/api');\n",
            [['line' => 2, 'name' => 'isPathUnder']],
        ];
        yield 'le brut passé au jugement : deux appels' => [
            "<?php\nCanonicalPath::isPathUnder(\$request->getPathInfo(), '/api');\n",
            [['line' => 2, 'name' => 'isPathUnder'], ['line' => 2, 'name' => 'getPathInfo']],
        ];
        yield 'appel sur plusieurs lignes' => [
            "<?php\n\$p = \$request\n    ->getPathInfo\n    ();\n",
            [['line' => 3, 'name' => 'getPathInfo']],
        ];
        // PHP ne distingue pas la casse d'un nom de méthode : `->getpathinfo()` lit le chemin brut tout autant.
        yield 'lecture du chemin brut, casse différente' => [
            "<?php\n\$p = \$request->getpathinfo();\n",
            [['line' => 2, 'name' => 'getpathinfo']],
        ];
        yield 'jugement d\'une chaîne, casse différente' => [
            "<?php\nCanonicalPath::ISPATHUNDER(\$path, '/api');\n",
            [['line' => 2, 'name' => 'ISPATHUNDER']],
        ];
    }

    #[DataProvider('formsTheCounterSees')]
    public function testTheCanonicalReadCounterSeesEachForm(string $code, int $expected): void
    {
        self::assertSame($expected, $this->canonicalReadsIn($code));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function formsTheCounterSees(): iterable
    {
        $canonical = CanonicalPath::class;
        $import = "<?php\nuse {$canonical};\n";

        yield 'nom court importé' => [$import."\$p = CanonicalPath::of(\$request);\n", 1];
        yield 'nom qualifié depuis l\'espace de noms parent' => [
            "<?php\nnamespace App\\Shared\\Infrastructure;\n\$p = Http\\CanonicalPath::of(\$request);\n",
            1,
        ];
        yield 'nom complet' => [
            "<?php\n\$p = \\App\\Shared\\Infrastructure\\Http\\CanonicalPath::of(\$request);\n",
            1,
        ];
        yield 'nom relatif dans l\'espace de noms de la classe' => [
            "<?php\nnamespace App\\Shared\\Infrastructure\\Http;\n\$p = namespace\\CanonicalPath::of(\$request);\n",
            1,
        ];
        yield 'méthode en casse différente' => [$import."\$p = CanonicalPath::OF(\$request);\n", 1];
        yield 'classe en casse différente' => [$import."\$p = canonicalpath::of(\$request);\n", 1];
        yield 'deux lectures' => [$import."\$a = CanonicalPath::of(\$x);\n\$b = \\{$canonical}::of(\$y);\n", 2];
        yield 'une autre méthode du même nom de préfixe' => [$import."\$p = CanonicalPath::often(\$request);\n", 0];
        yield 'une autre classe' => ["<?php\n\$p = Other::of(\$request);\n", 0];
        yield 'une autre classe de nom complet' => ["<?php\n\$p = \\App\\OtherCanonicalPath::of(\$request);\n", 0];
        yield 'un nom sans appel' => [$import."\$n = CanonicalPath::of;\n", 0];
        yield 'un commentaire' => [$import."// CanonicalPath::of(\$request)\n", 0];
        yield 'une chaîne' => [$import."\$m = 'CanonicalPath::of(\$request)';\n", 0];

        // Le nom se résout comme PHP le résout : seule la classe de CanonicalPath compte, pas un homonyme.
        yield 'alias de la classe importée' => ["<?php\nuse {$canonical} as CP;\n\$p = CP::of(\$request);\n", 1];
        yield 'nom court dans son espace de noms' => [
            "<?php\nnamespace App\\Shared\\Infrastructure\\Http;\n\$p = CanonicalPath::of(\$request);\n",
            1,
        ];
        yield 'homonyme de nom complet d\'un autre espace de noms' => ["<?php\n\$p = \\Other\\CanonicalPath::of(\$request);\n", 0];
        yield 'homonyme importé' => ["<?php\nuse Other\\CanonicalPath;\n\$p = CanonicalPath::of(\$request);\n", 0];
        yield 'alias d\'un homonyme' => ["<?php\nuse Other\\CanonicalPath as CP;\n\$p = CP::of(\$request);\n", 0];
        yield 'homonyme de l\'espace de noms courant' => ["<?php\nnamespace App\\Other;\n\$p = CanonicalPath::of(\$request);\n", 0];
        yield 'alias qui en cache un autre nom' => ["<?php\nuse {$canonical} as Other;\n\$p = Other::of(\$request);\n", 1];
        yield 'import d\'un espace de noms précédent oublié' => [
            "<?php\nnamespace A;\nuse {$canonical};\nnamespace B;\n\$p = CanonicalPath::of(\$request);\n",
            0,
        ];
    }

    #[DataProvider('formsTheDetectorLeavesAlone')]
    public function testTheDetectorLeavesTheCanonicalFormsAlone(string $code): void
    {
        self::assertSame([], $this->forbiddenCallsIn($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formsTheDetectorLeavesAlone(): iterable
    {
        yield 'lecture canonique' => ["<?php\n\$p = CanonicalPath::of(\$request);\n"];
        yield 'jugement d\'une requête' => ["<?php\nCanonicalPath::isUnder(\$request, '/api');\n"];
        yield 'commentaire' => ["<?php\n// \$request->getPathInfo() est brut\n/** CanonicalPath::isPathUnder(\$p, '/api') */\n"];
        yield 'chaîne' => ["<?php\n\$m = 'getPathInfo() est brut, isPathUnder() aussi';\n"];
        yield 'déclaration d\'une méthode de même nom' => [
            "<?php\nfinal class A { public function getPathInfo(): string { return ''; } }\n",
        ];
        yield 'nom sans appel' => ["<?php\n\$n = Foo::isPathUnder;\n"];
    }

    /**
     * @return list<string> chemins des fichiers de `src/`, `CanonicalPath.php` excepté
     */
    private function sourcesOutsideCanonicalPath(): array
    {
        $backend = RepositoryFiles::backend();

        return array_values(array_filter(
            PhpSources::files(RepositoryFiles::backend('src')),
            static fn (string $path): bool => $backend.self::CANONICAL_PATH_FILE !== $path,
        ));
    }

    /**
     * Les appels `getPathInfo(` et `isPathUnder(` d'un code source, un nom de méthode sans égard à la casse (PHP n'en
     * fait pas) : la forme d'appel (`->`, `?->` ou `::`, puis `(`) est celle de {@see MemberCalls}.
     *
     * @return list<array{line: int, name: string}>
     */
    private function forbiddenCallsIn(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);
        $found = [];
        foreach ($tokens as $index => $token) {
            $isForbidden = $token->is(\T_STRING)
                && \in_array(strtolower($token->text), self::FORBIDDEN_CALLS, true)
                && MemberCalls::isCallAt($tokens, $index);
            if ($isForbidden) {
                $found[] = ['line' => $token->line, 'name' => $token->text];
            }
        }

        return $found;
    }

    /**
     * Le nombre de `CanonicalPath::of(` d'un code source : un nom qui **résout** vers la classe de
     * {@see CanonicalPath} (nom complet, nom court importé ou dans son espace de noms, alias, nom qualifié ou relatif,
     * comme {@see ClassNameResolver} les résout), jamais un homonyme d'un autre espace de noms ; la méthode sans
     * égard à la casse, la forme d'appel (`::`, puis `(`) celle de {@see MemberCalls}, lue une fois.
     */
    private function canonicalReadsIn(string $code): int
    {
        $tokens = PhpSources::significantTokens($code);
        $canonical = strtolower(CanonicalPath::class);
        $count = 0;
        foreach (ClassNameResolver::scopes($tokens) as $index => [$namespace, $imports]) {
            $isRead = $tokens[$index]->is(ClassNameResolver::NAMES)
                && $canonical === ClassNameResolver::resolve($tokens[$index], $namespace, $imports)
                && 'of' === strtolower($tokens[$index + 2]->text ?? '')
                && MemberCalls::isStaticCallAt($tokens, $index + 2);
            if ($isRead) {
                ++$count;
            }
        }

        return $count;
    }
}
