<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

use Normalizer;
use PHPUnit\Framework\Assert;

/**
 * Cherche l'auteur du site dans ce que le serveur MCP publie de lui-même
 * (ADR 0006 D8) : son nom, ses initiales, ou un fragment de son CV nominatif.
 *
 * Ni le nom ni le CV ne viennent du dépôt : le nom vient de la configuration
 * de test (`MCP_D8_AUTHOR_NAME`, fictif en local, le vrai par un secret en CI),
 * le CV du fichier que sert `GET /api/cv` (`app.cv_file_path`). Aussi ce qui
 * est trouvé n'est-il **jamais cité** : une {@see AuthorMention} nomme
 * l'endroit, la nature et le numéro du terme, pas le terme ni le texte qui le
 * porte — un message d'échec finit dans un journal de CI ou une session
 * d'assistant, et GitHub ne masque que la valeur entière du secret.
 *
 * Le texte est replié avant la comparaison : NFKD (la pleine chasse
 * `ＤＵＲＡＮＤ` devient `DURAND`), accents et caractères de format retirés
 * (un trait d'union conditionnel ou un espace de largeur nulle glissé dans le
 * nom ne le coupe plus), minuscules. Sont cherchés :
 *
 *  - {@see MentionKind::Name} : le nom complet, et chacun de ses mots d'au
 *    moins {@see self::MIN_NAME_PART_LENGTH} lettres, comme mots entiers
 *    (« Durand » ne se trouve pas dans « Durandal ») ;
 *  - {@see MentionKind::JoinedName} : à défaut, le nom accolé ou l'un de ses
 *    mots d'au moins {@see self::MIN_JOINED_PART_LENGTH} lettres à l'intérieur
 *    d'un mot du texte (`prenomnom22@…`, `PrenomNom`) — au prix de faux
 *    positifs rares, qui disent où regarder ;
 *  - {@see MentionKind::Initials} : les initiales en capitales comme mot
 *    (`XY`, `X. Y.`) ou en minuscules en tête d'un identifiant (`xy-…`, `xy_…`,
 *    `xy.…`) — jamais en minuscules seules, où deux lettres sont souvent un
 *    mot (« ce ») ;
 *  - {@see MentionKind::CvFragment} : chaque suite de
 *    {@see self::CV_FRAGMENT_WORDS} mots consécutifs du CV.
 */
final readonly class AuthorMentions
{
    private const int MIN_NAME_PART_LENGTH = 3;

    /** Plus long que pour un mot entier : à l'intérieur d'un mot, trois lettres se rencontrent partout. */
    private const int MIN_JOINED_PART_LENGTH = 5;

    /** Assez long pour qu'une rencontre fortuite avec une description soit invraisemblable. */
    private const int CV_FRAGMENT_WORDS = 6;

    /**
     * @param list<string> $nameTerms        mots repliés, joints et entourés d'espaces
     * @param list<string> $joinedTerms      lettres repliées, sans séparateur
     * @param list<string> $initialsPatterns expressions régulières, sur le texte replié en NFKC seulement
     * @param list<string> $cvFragments      comme les termes du nom
     */
    private function __construct(
        private array $nameTerms,
        private array $joinedTerms,
        private array $initialsPatterns,
        private array $cvFragments,
    ) {
    }

    /**
     * @param string      $authorName le nom de l'auteur ; vide, le test échoue
     * @param string|null $cvText     le texte du CV nominatif, null s'il n'y en a pas
     */
    public static function of(string $authorName, ?string $cvText): self
    {
        $nameWords = self::words($authorName);
        // Un nom vide ne chercherait rien, et la garde passerait au vert
        // sans rien garder.
        Assert::assertNotSame([], $nameWords, 'Le nom de l\'auteur à chercher est vide.');

        $nameTerms = [self::term($nameWords)];
        $joinedTerms = [implode('', $nameWords)];
        foreach ($nameWords as $word) {
            if (mb_strlen($word) >= self::MIN_NAME_PART_LENGTH) {
                $nameTerms[] = self::term([$word]);
            }
            if (mb_strlen($word) >= self::MIN_JOINED_PART_LENGTH) {
                $joinedTerms[] = $word;
            }
        }

        $cvWords = self::words($cvText ?? '');
        $cvFragments = [];
        $wordCount = \count($cvWords);
        for ($start = 0; $start + self::CV_FRAGMENT_WORDS <= $wordCount; ++$start) {
            $cvFragments[] = self::term(\array_slice($cvWords, $start, self::CV_FRAGMENT_WORDS));
        }

        return new self(
            array_values(array_unique($nameTerms)),
            array_values(array_unique($joinedTerms)),
            self::initialsPatterns($nameWords),
            array_values(array_unique($cvFragments)),
        );
    }

    /** Nombre de fragments du CV cherchés : zéro quand le CV de test n'a pas de texte. */
    public function cvFragmentCount(): int
    {
        return \count($this->cvFragments);
    }

    /**
     * Les mentions de l'auteur dans `$published`.
     *
     * Chaque chaîne est lue, clés comprises (un nom de propriété de schéma est
     * publié lui aussi). Une exception admise par l'ADR 0006 sous D8 n'est
     * admise qu'à son chemin et pour sa valeur exacte : la même valeur
     * ailleurs, ou une autre valeur à ce chemin, est lue comme le reste.
     *
     * @param array<mixed>          $published ce que le serveur publie, par source
     * @param array<string, string> $admitted  chemin => valeur admise
     *
     * @return list<AuthorMention> triées par chemin
     */
    public function in(array $published, array $admitted = []): array
    {
        $found = [];
        foreach (self::strings($published, '') as [$path, $text]) {
            if (($admitted[$path] ?? null) !== $text) {
                array_push($found, ...$this->mentionsIn($path, $text));
            }
        }
        usort($found, static fn (AuthorMention $left, AuthorMention $right): int => [$left->path, $left->kind->value, $left->termIndex] <=> [$right->path, $right->kind->value, $right->termIndex]);

        return $found;
    }

    /**
     * @return list<AuthorMention>
     */
    private function mentionsIn(string $path, string $text): array
    {
        $haystack = self::term(self::words($text));
        $mentions = [];
        foreach ($this->nameTerms as $index => $term) {
            if (str_contains($haystack, $term)) {
                $mentions[] = new AuthorMention($path, MentionKind::Name, $index);
            }
        }

        // Accolé seulement à défaut d'un mot entier : un nom écrit en clair se
        // trouve aussi à l'intérieur de son propre mot.
        if ([] === $mentions) {
            $tokens = $this->joinedTokens($text);
            foreach ($this->joinedTerms as $index => $term) {
                foreach ($tokens as $token) {
                    if (str_contains($token, $term)) {
                        $mentions[] = new AuthorMention($path, MentionKind::JoinedName, $index);

                        break;
                    }
                }
            }
        }

        $visible = $this->withoutFormatCharacters($text);
        foreach ($this->initialsPatterns as $index => $pattern) {
            $count = preg_match_all($pattern, $visible);
            if (false === $count) {
                Assert::fail('Recherche des initiales impossible : '.preg_last_error_msg());
            }
            for ($occurrence = 0; $occurrence < $count; ++$occurrence) {
                $mentions[] = new AuthorMention($path, MentionKind::Initials, $index);
            }
        }

        foreach ($this->cvFragments as $index => $fragment) {
            if (str_contains($haystack, $fragment)) {
                $mentions[] = new AuthorMention($path, MentionKind::CvFragment, $index);
            }
        }

        return $mentions;
    }

    /**
     * Chaque chaîne de l'arbre, clés comprises, avec son chemin.
     *
     * @param array<mixed> $tree
     *
     * @return iterable<array{string, string}>
     */
    private static function strings(array $tree, string $prefix): iterable
    {
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.(\is_int($key) ? '['.$key.']' : '.'.$key);
            if (\is_string($key)) {
                yield [$path.' (clé)', $key];
            }
            if (\is_string($value)) {
                yield [$path, $value];
            } elseif (\is_array($value)) {
                yield from self::strings($value, $path);
            }
        }
    }

    /**
     * Les trois formes d'initiales, s'il y en a au moins deux.
     *
     * @param list<string> $nameWords
     *
     * @return list<string>
     */
    private static function initialsPatterns(array $nameWords): array
    {
        $letters = array_map(static fn (string $word): string => mb_substr($word, 0, 1), $nameWords);
        if (\count($letters) < 2) {
            return [];
        }

        $lower = preg_quote(implode('', $letters), '/');
        $upper = preg_quote(mb_strtoupper(implode('', $letters)), '/');
        $dotted = implode('\.\s?', array_map(static fn (string $letter): string => preg_quote(mb_strtoupper($letter), '/'), $letters)).'\.';
        $start = '(?<![\p{L}\p{N}])';

        return [
            '/'.$start.$lower.'(?=[-_.])/u',
            '/'.$start.$upper.'(?![\p{L}\p{N}])/u',
            '/'.$start.$dotted.'/u',
        ];
    }

    /**
     * Les mots du texte (séparés par des blancs), chacun réduit à ses lettres
     * repliées : `Prenom.Nom22@example.test` → `prenomnomexampletest`.
     *
     * @return list<string>
     */
    private function joinedTokens(string $text): array
    {
        $tokens = [];
        foreach (self::split('/[\s\p{Z}]+/u', $text) as $token) {
            $letters = self::replaced('/[^\p{L}]+/u', '', self::folded($token));
            if ('' !== $letters) {
                $tokens[] = $letters;
            }
        }

        return $tokens;
    }

    /**
     * Les mots d'un texte, repliés.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return self::split('/[^\p{L}\p{N}]+/u', self::folded($text));
    }

    /** NFKD, sans marques combinantes (les accents) ni caractères de format, en minuscules. */
    private static function folded(string $text): string
    {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);
        if (false === $decomposed) {
            Assert::fail('Normalisation NFKD impossible.');
        }

        return mb_strtolower(self::replaced('/[\p{Mn}\p{Cf}]+/u', '', $decomposed));
    }

    /** NFKC et sans caractères de format, la casse gardée : la forme où se lisent les initiales. */
    private function withoutFormatCharacters(string $text): string
    {
        $composed = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (false === $composed) {
            Assert::fail('Normalisation NFKC impossible.');
        }

        return self::replaced('/\p{Cf}+/u', '', $composed);
    }

    /**
     * @return list<string>
     */
    private static function split(string $pattern, string $text): array
    {
        // Les échecs seuls deviennent des assertions : une par chaîne lue
        // gonflait le décompte de plus de cent mille.
        $parts = preg_split($pattern, $text, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $parts) {
            Assert::fail('Découpage impossible : '.preg_last_error_msg());
        }

        return $parts;
    }

    private static function replaced(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? Assert::fail('Remplacement impossible : '.preg_last_error_msg());
    }

    /**
     * Des mots joints par une espace et entourés d'espaces : un terme ainsi
     * écrit ne se trouve dans un texte écrit de même qu'à des limites de mots.
     *
     * @param list<string> $words
     */
    private static function term(array $words): string
    {
        return ' '.implode(' ', $words).' ';
    }
}
