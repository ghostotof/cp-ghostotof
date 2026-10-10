<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpToken;

/**
 * Recense les imports mal ordonnés d'un répertoire (issue #394), selon le tri
 * de php-cs-fixer `ordered_imports` `alpha` (`OrderedImportsFixer::sortAlphabetically()`,
 * v3.95.27) :
 * - la clé est l'import tel qu'écrit, alias compris (`Foo as Bar`) ;
 * - chaque `\` y devient une espace, donc `Foo\Bar` précède `Foo2` ;
 * - les clés se comparent par `strcasecmp`, sans tenir compte de la casse.
 *
 * Seuls les `use` de premier niveau comptent. Comme dans php-cs-fixer, un bloc
 * s'arrête au premier jeton qui n'est pas un `use`, et le bloc suivant se trie
 * à part. Un `use` de trait, à l'intérieur d'une classe, ou le `use (…)` d'une
 * fonction anonyme ne sont pas des imports.
 *
 * Trois formes sont refusées au lieu d'être triées : `use function`, `use const`
 * et l'import groupé `use Foo\{A, B};`. La règle du projet interdit les deux
 * premières, et Rector ne produit jamais la troisième.
 *
 * Limite assumée : un espace de noms à accolades (`namespace App { … }`) met
 * ses `use` au deuxième niveau, et le recensement ne les voit pas. Le projet
 * n'en écrit pas.
 */
final class UnsortedImports
{
    public const string FIX = "Imports mal ordonnés. Tri ponctuel, depuis la racine du dépôt :\n"
        ."docker compose exec -u dev backend sh -c '".PhpSources::CS_FIXER_DOWNLOAD
        .' && for d in src tests; do php var/php-cs-fixer.phar fix --using-cache=no --rules=ordered_imports $d; done\'';

    /**
     * @return list<string> `chemin:ligne problème` pour chaque import fautif, triés pour un diagnostic stable
     */
    public static function in(string ...$directories): array
    {
        $found = [];
        foreach ($directories as $directory) {
            foreach (PhpSources::files($directory) as $path) {
                foreach (self::inCode((string) file_get_contents($path)) as $problem) {
                    $found[] = $path.':'.$problem;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string> `ligne problème` pour chaque import fautif, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);
        $problems = [];
        $depth = 0;
        /** @var string|null $previous dernier import du bloc en cours, tel qu'écrit */
        $previous = null;
        $blockEnd = null;
        $count = \count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            // `"{$a}"` ouvre par T_CURLY_OPEN, dont le texte est `{` ; `"${a}"` par `${`.
            if ('{' === $token->text || $token->is(\T_DOLLAR_OPEN_CURLY_BRACES)) {
                ++$depth;
            } elseif ('}' === $token->text) {
                --$depth;
            }
            if (0 !== $depth || !$token->is(\T_USE) || '(' === ($tokens[$index + 1] ?? null)?->text) {
                continue;
            }

            $end = self::statementEnd($tokens, $index);
            if ($blockEnd !== $index - 1) {
                $previous = null;
            }
            $blockEnd = $end;

            $forbidden = self::forbiddenForm($tokens, $index, $end);
            if (null !== $forbidden) {
                $problems[] = $token->line.' '.$forbidden;
                $previous = null;
                $index = $end;

                continue;
            }

            foreach (self::imports($tokens, $index + 1, $end) as [$import, $line]) {
                if (null !== $previous && strcasecmp(self::key($previous), self::key($import)) > 0) {
                    $problems[] = $line.' '.$import.' doit précéder '.$previous;
                }
                $previous = $import;
            }
            $index = $end;
        }

        return $problems;
    }

    /**
     * @param list<PhpToken> $tokens
     *
     * @return int index du `;` qui clôt l'instruction (ou du dernier jeton, sur un code tronqué)
     */
    private static function statementEnd(array $tokens, int $index): int
    {
        $last = \count($tokens) - 1;
        while ($index < $last && ';' !== $tokens[$index]->text) {
            ++$index;
        }

        return $index;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function forbiddenForm(array $tokens, int $use, int $end): ?string
    {
        $next = $tokens[$use + 1] ?? null;
        if (null !== $next && $next->is(\T_FUNCTION)) {
            return 'use function interdit';
        }
        if (null !== $next && $next->is(\T_CONST)) {
            return 'use const interdit';
        }
        for ($index = $use + 1; $index < $end; ++$index) {
            if ('{' === $tokens[$index]->text) {
                return 'import groupé interdit';
            }
        }

        return null;
    }

    /**
     * Les imports d'un `use A, B as C;`, tels qu'écrits, avec la ligne de chacun.
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<array{string, int}>
     */
    private static function imports(array $tokens, int $start, int $end): array
    {
        $imports = [];
        $import = '';
        $line = 0;
        for ($index = $start; $index <= $end; ++$index) {
            $token = $tokens[$index];
            if (',' === $token->text || ';' === $token->text) {
                $imports[] = [$import, $line];
                $import = '';

                continue;
            }
            if ('' === $import) {
                $line = $token->line;
            }
            // Les espaces sont tombés avec les jetons ignorés : seul `as` en demande.
            $import .= $token->is(\T_AS) ? ' as ' : $token->text;
        }

        return $imports;
    }

    /**
     * La clé de tri de php-cs-fixer : `\` devient une espace, qui précède
     * chiffres, lettres et `_`.
     */
    private static function key(string $import): string
    {
        return str_replace('\\', ' ', $import);
    }
}
