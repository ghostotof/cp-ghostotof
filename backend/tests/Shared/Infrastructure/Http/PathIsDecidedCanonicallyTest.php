<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use PhpToken;
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
 * écrit `->`, `?->` ou `::`, y compris en appelable de première classe (`->getPathInfo(...)`), hors de
 * `CanonicalPath.php`. Aucun appel légitime n'existe aujourd'hui, donc aucune exemption.
 *
 * Ce que la garde ne voit pas, limite assumée : un chemin lu autrement que par ces deux noms
 * (`$request->server->get('PATH_INFO')`, `getRequestUri()`, `$_SERVER`), un nom construit à l'exécution
 * (`$request->{'getPathInfo'}()`) et un `Request` lu par un tiers de `vendor/`.
 */
final class PathIsDecidedCanonicallyTest extends TestCase
{
    private const string CANONICAL_PATH_FILE = '/src/Shared/Infrastructure/Http/CanonicalPath.php';

    private const array FORBIDDEN_CALLS = ['getPathInfo', 'isPathUnder'];

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
     * Les appels `getPathInfo(` et `isPathUnder(` d'un code source : un nom, suivi de `(`, précédé de `->`, `?->` ou `::`.
     *
     * @return list<array{line: int, name: string}>
     */
    private function forbiddenCallsIn(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);
        $found = [];
        foreach ($tokens as $index => $token) {
            if (!$token->is(\T_STRING) || !\in_array($token->text, self::FORBIDDEN_CALLS, true)) {
                continue;
            }

            $before = $tokens[$index - 1] ?? null;
            $after = $tokens[$index + 1] ?? null;
            if (
                $before instanceof PhpToken && $before->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON])
                && $after instanceof PhpToken && '(' === $after->text
            ) {
                $found[] = ['line' => $token->line, 'name' => $token->text];
            }
        }

        return $found;
    }

    /**
     * Le nombre de `CanonicalPath::of(` d'un code source, nom court ou complet.
     */
    private function canonicalReadsIn(string $code): int
    {
        $tokens = PhpSources::significantTokens($code);
        $count = 0;
        foreach ($tokens as $index => $token) {
            $isRead = $token->is(\T_STRING) && 'CanonicalPath' === $token->text
                && ($tokens[$index + 1] ?? null)?->is(\T_DOUBLE_COLON) === true
                && ($tokens[$index + 2] ?? null)?->text === 'of'
                && ($tokens[$index + 3] ?? null)?->text === '(';
            if ($isRead) {
                ++$count;
            }
        }

        return $count;
    }
}
