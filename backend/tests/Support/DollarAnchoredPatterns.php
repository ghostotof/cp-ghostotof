<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recense les expressions régulières d'un répertoire qui s'ancrent par `$`
 * sans modificateur `D` ni `m` (issue #409, suite de #386).
 *
 * En PCRE, `$` accepte aussi la position qui précède un `\n` final : un motif
 * de validation `/^[a-z]+$/` laisse donc passer `"abc\n"`. Le défaut a été
 * corrigé sur `CpgUser::USERNAME_PATTERN` (#386) puis sur trois autres motifs
 * (#409) ; ce recenseur empêche le suivant d'arriver. Le remède est `\z`, la
 * fin absolue du sujet.
 *
 * Toute chaîne littérale du code compte, pas seulement les constantes et les
 * `#[Assert\Regex]` que vise l'issue : un `preg_match('/^…$/', …)` écrit en
 * ligne a exactement le même défaut. Une chaîne est tenue pour un motif quand
 * elle en a la forme — un délimiteur PCRE, le délimiteur fermant, puis
 * uniquement des modificateurs que PHP connaît —, ce qui écarte les chemins
 * (`/api/account/base-access` : `base-access` n'est pas une liste de
 * modificateurs) et les gabarits de remplacement (`${1}***`, sans délimiteur
 * fermant).
 *
 * Dans le motif, seul compte un `$` ancre : un `\$` échappé, un `$` dans une
 * classe de caractères (`[#$%]`) ou dans une citation littérale (de la
 * séquence Q à la séquence E, chacune précédée d'une barre oblique inverse)
 * est un caractère littéral. Les modificateurs `D` (`$` ne vaut plus que la
 * fin absolue) et `m` (`$` vaut, voulu, chaque fin de ligne) rendent l'ancre
 * légitime.
 *
 * Limites assumées, sans cas dans `src/` aujourd'hui :
 * - un motif construit par concaténation (`'/^'.$x.'$/'`) n'est pas reconstitué ;
 * - un heredoc, ou une chaîne qui interpole une variable, n'est pas lu ;
 * - en mode `x`, un `$` dans un commentaire `# …` est signalé à tort (faux
 *   positif, donc refus par excès : jamais un défaut qui passe).
 */
final class DollarAnchoredPatterns
{
    /** Les modificateurs PCRE que PHP accepte : une chaîne suivie d'autre chose n'est pas un motif. */
    private const string MODIFIERS = 'imsxuADSUXJnr';

    /** Délimiteurs ouvrants qui se referment sur leur pendant, comme le veut PCRE. */
    private const array BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * @return list<string> `chemin:ligne motif` de chaque motif fautif, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        $found = [];
        foreach (PhpSources::files($directory) as $path) {
            foreach (self::inCode((string) file_get_contents($path)) as [$line, $pattern]) {
                $found[] = $path.':'.$line.' '.$pattern;
            }
        }

        return $found;
    }

    /**
     * @return list<array{int, string}> ligne et motif décodé de chaque motif fautif, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $found = [];
        foreach (PhpSources::significantTokens($code) as $token) {
            if (!$token->is(\T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }

            $value = self::literalValue($token->text);
            if (self::anchorsOnDollar($value)) {
                $found[] = [$token->line, $value];
            }
        }

        return $found;
    }

    /**
     * Vrai quand la chaîne a la forme d'un motif PCRE et qu'elle contient un
     * `$` ancre, sans `D` ni `m` pour en fixer le sens.
     */
    public static function anchorsOnDollar(string $value): bool
    {
        $parts = self::split($value);
        if (null === $parts) {
            return false;
        }

        [$body, $modifiers] = $parts;
        if (str_contains($modifiers, 'D') || str_contains($modifiers, 'm')) {
            return false;
        }

        return self::hasDollarAnchor($body);
    }

    /**
     * Sépare un motif en corps et modificateurs, ou rend `null` si la chaîne
     * n'a pas la forme d'un motif.
     *
     * @return array{string, string}|null
     */
    private static function split(string $value): ?array
    {
        if (\strlen($value) < 2) {
            return null;
        }

        $opening = $value[0];
        // PCRE refuse comme délimiteur un caractère alphanumérique, la barre
        // oblique inverse et les blancs.
        if (ctype_alnum($opening) || '\\' === $opening || ctype_space($opening)) {
            return null;
        }

        $closing = self::BRACKETS[$opening] ?? $opening;
        $end = strrpos($value, $closing);
        if (false === $end || 0 === $end) {
            return null;
        }

        $modifiers = substr($value, $end + 1);
        if (\strlen($modifiers) !== strspn($modifiers, self::MODIFIERS)) {
            return null;
        }

        return [substr($value, 1, $end - 1), $modifiers];
    }

    /**
     * Parcourt le corps du motif et cherche un `$` hors échappement, hors
     * classe de caractères et hors citation littérale (séquences Q…E).
     */
    private static function hasDollarAnchor(string $body): bool
    {
        $length = \strlen($body);
        $inClass = false;
        for ($i = 0; $i < $length; ++$i) {
            $char = $body[$i];

            if ('\\' === $char) {
                if ('Q' === ($body[$i + 1] ?? '')) {
                    // Tout est littéral jusqu'à la séquence E, ou jusqu'à la fin du motif.
                    $quoteEnd = strpos($body, '\\E', $i + 2);
                    if (false === $quoteEnd) {
                        return false;
                    }
                    $i = $quoteEnd + 1;

                    continue;
                }
                ++$i;

                continue;
            }

            if ($inClass) {
                if ('[' === $char && ':' === ($body[$i + 1] ?? '')) {
                    // Classe POSIX `[:alpha:]` : son `]` ne ferme pas la classe englobante.
                    $posixEnd = strpos($body, ':]', $i + 2);
                    if (false !== $posixEnd) {
                        $i = $posixEnd + 1;
                    }
                } elseif (']' === $char) {
                    $inClass = false;
                }

                continue;
            }

            if ('[' === $char) {
                $inClass = true;
                // Un `]` placé en tête de classe (après un éventuel `^`) est littéral.
                if ('^' === ($body[$i + 1] ?? '')) {
                    ++$i;
                }
                if (']' === ($body[$i + 1] ?? '')) {
                    ++$i;
                }
            } elseif ('$' === $char) {
                return true;
            }
        }

        return false;
    }

    /**
     * La valeur d'une chaîne littérale telle que PHP la construit : un motif
     * écrit entre guillemets doubles (`"/\\S+/"`) ne se lit qu'une fois ses
     * séquences d'échappement résolues.
     */
    private static function literalValue(string $literal): string
    {
        // Préfixe binaire `b'…'`, sans effet sur la valeur.
        if ('b' === strtolower($literal[0])) {
            $literal = substr($literal, 1);
        }

        $quote = $literal[0];
        $inner = substr($literal, 1, -1);

        if ("'" === $quote) {
            return (string) preg_replace('/\\\\([\\\\\'])/', '$1', $inner);
        }

        return (string) preg_replace_callback(
            '/\\\\(?:([\\\\"$nrtvef])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            static fn (array $match): string => self::escapeValue($match),
            $inner,
        );
    }

    /**
     * Une séquence d'échappement de chaîne entre guillemets doubles. Les
     * séquences que PHP ne connaît pas (`\s`, `\d`…) ne sont pas capturées par
     * l'appelant : elles restent telles quelles, comme dans PHP.
     *
     * @param array<array-key, string> $match
     */
    private static function escapeValue(array $match): string
    {
        $simple = $match[1] ?? '';
        if ('' !== $simple) {
            return match ($simple) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'v' => "\v",
                'e' => "\e",
                'f' => "\f",
                default => $simple,
            };
        }

        $octal = $match[2] ?? '';
        if ('' !== $octal) {
            // PHP tronque à l'octet un octal au-delà de \377.
            return \chr((int) octdec($octal) & 0xFF);
        }

        $hex = $match[3] ?? '';
        if ('' !== $hex) {
            return \chr((int) hexdec($hex) & 0xFF);
        }

        return mb_chr((int) hexdec($match[4] ?? '0'), 'UTF-8');
    }
}
