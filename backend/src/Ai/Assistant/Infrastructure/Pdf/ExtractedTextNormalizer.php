<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Pdf;

/**
 * Remet en prose le texte que pdftotext tire du CV nominatif (spec 0005 D7).
 *
 * pdftotext sépare les pages par un saut de page (\f) et les paragraphes par
 * une ligne vide, mais garde les retours à la ligne de la mise en page et ne
 * détache pas un titre du paragraphe qui le suit. D'où quatre opérations, dans
 * cet ordre :
 *
 * 1. caractères de contrôle retirés, espaces et tabulations compressés ;
 * 2. une ligne répétée au bord de chaque page (en-tête, pied, numéro de page)
 *    n'est gardée qu'à sa première occurrence — supprimée partout, un CV qui ne
 *    porte le prénom ou l'adresse qu'en en-tête les perdrait ;
 * 3. une ligne vide ou un saut de page séparent deux paragraphes ;
 * 4. dans un paragraphe, une ligne n'est recollée à la suivante que si elle
 *    remplit sa colonne et ne finit pas une phrase : c'est ce qui distingue un
 *    retour automatique d'un titre ou d'un élément de liste dont la puce n'a
 *    pas survécu à l'extraction.
 *
 * Heuristique assumée : un retour automatique tombé juste après un point coupe
 * le paragraphe en deux, ce qui ne fait rien perdre au modèle ; recoller un
 * titre à sa phrase, si.
 *
 * Pure et sans état : ni fichier, ni cache, ni journal (D7, D10).
 */
final readonly class ExtractedTextNormalizer
{
    private const string PAGE_BREAK = "\f";

    /** Lignes non vides examinées en haut et en bas de chaque page. */
    private const int PAGE_EDGE_LINES = 3;

    /**
     * Part de la plus longue ligne du paragraphe à partir de laquelle une ligne
     * « remplit sa colonne » : un retour automatique laisse au plus la place du
     * mot suivant, un titre ou un élément de liste bien davantage.
     */
    private const float FULL_LINE_RATIO = 0.75;

    /** Ponctuation qui termine une phrase ou annonce une suite. */
    private const string SENTENCE_END = '/[.!?:;…]$/u';

    public function normalize(string $text): string
    {
        $pages = array_map($this->cleanLines(...), explode(self::PAGE_BREAK, $text));

        $paragraphs = [];
        foreach ($this->withoutRepeatedEdgeLines($pages) as $lines) {
            foreach ($this->paragraphs($lines) as $paragraph) {
                $paragraphs[] = $this->joinWrappedLines($paragraph);
            }
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * @return list<string> les lignes de la page, nettoyées ; une ligne vide
     *                      reste vide, elle sépare deux paragraphes
     */
    private function cleanLines(string $page): array
    {
        $lines = preg_split('/\R/u', $page);
        if (false === $lines) {
            throw new CvTextExtractionException('Normalisation du texte du CV impossible.');
        }

        // \p{Cc} couvre aussi \t et \n : la page est déjà découpée en lignes,
        // et une tabulation devient un espace avant d'être compressée.
        return array_map(
            static fn (string $line): string => trim(self::replace('/\h+/u', ' ', self::replace('/\p{Cc}/u', '', str_replace("\t", ' ', $line)))),
            $lines,
        );
    }

    /**
     * @param list<list<string>> $pages
     *
     * @return list<list<string>>
     */
    private function withoutRepeatedEdgeLines(array $pages): array
    {
        $pages = array_values(array_filter($pages, static fn (array $lines): bool => [] !== array_filter($lines, static fn (string $line): bool => '' !== $line)));
        if (\count($pages) < 2) {
            return $pages;
        }

        $repeated = array_intersect(...array_map($this->edgeKeys(...), $pages));
        if ([] === $repeated) {
            return $pages;
        }

        $seen = [];
        foreach ($pages as $index => $lines) {
            $edges = $this->edgeIndexes($lines);
            foreach ($lines as $position => $line) {
                $key = self::key($line);
                if (!\in_array($position, $edges, true) || !\in_array($key, $repeated, true)) {
                    continue;
                }
                if (isset($seen[$key])) {
                    $lines[$position] = '';
                }
                $seen[$key] = true;
            }
            $pages[$index] = $lines;
        }

        return $pages;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function edgeKeys(array $lines): array
    {
        return array_values(array_unique(array_map(static fn (int $position): string => self::key($lines[$position]), $this->edgeIndexes($lines))));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<int>
     */
    private function edgeIndexes(array $lines): array
    {
        $filled = array_keys(array_filter($lines, static fn (string $line): bool => '' !== $line));

        return array_values(array_unique([
            ...\array_slice($filled, 0, self::PAGE_EDGE_LINES),
            ...\array_slice($filled, -self::PAGE_EDGE_LINES),
        ]));
    }

    /** Deux numéros de page sont la même ligne : seuls les chiffres changent. */
    private static function key(string $line): string
    {
        return self::replace('/\d+/u', '#', $line);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<non-empty-list<string>>
     */
    private function paragraphs(array $lines): array
    {
        $paragraphs = [];
        $current = [];
        foreach ($lines as $line) {
            if ('' !== $line) {
                $current[] = $line;

                continue;
            }
            if ([] !== $current) {
                $paragraphs[] = $current;
                $current = [];
            }
        }
        if ([] !== $current) {
            $paragraphs[] = $current;
        }

        return $paragraphs;
    }

    /**
     * @param non-empty-list<string> $lines
     */
    private function joinWrappedLines(array $lines): string
    {
        $fullWidth = self::FULL_LINE_RATIO * max(array_map(mb_strlen(...), $lines));

        $text = $lines[0];
        for ($index = 1, $count = \count($lines); $index < $count; ++$index) {
            $previous = $lines[$index - 1];
            $wrapped = mb_strlen($previous) >= $fullWidth && 1 !== preg_match(self::SENTENCE_END, $previous);
            $text .= ($wrapped ? ' ' : "\n").$lines[$index];
        }

        return $text;
    }

    /**
     * preg_replace rend `null` sur un UTF-8 invalide : le texte d'un PDF peut
     * en contenir, et un champ vidé en silence ferait dire à l'assistant que le
     * CV ne mentionne rien.
     */
    private static function replace(string $pattern, string $replacement, string $value): string
    {
        return preg_replace($pattern, $replacement, $value)
            ?? throw new CvTextExtractionException('Normalisation du texte du CV impossible.');
    }
}
