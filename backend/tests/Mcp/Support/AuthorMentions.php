<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

use Normalizer;
use PHPUnit\Framework\Assert;

/**
 * Cherche l'auteur du site dans ce que le serveur MCP publie de lui-même
 * (ADR 0006 D8) : son nom, ou un fragment de son CV nominatif.
 *
 * Ni le nom ni le CV ne viennent du dépôt : le nom vient de la configuration
 * de test (`MCP_D8_AUTHOR_NAME`, fictif en local, le vrai par un secret en CI),
 * le CV du fichier que sert `GET /api/cv` (`app.cv_file_path`). Aussi ce qui
 * est trouvé n'est-il **jamais cité** : une violation nomme l'endroit et le
 * numéro du terme, pas le terme ni le texte qui le porte — un message d'échec
 * finit dans un journal de CI ou une session d'assistant, et GitHub ne masque
 * que la valeur entière du secret, pas un de ses mots.
 *
 * La comparaison se fait sur des mots : casse, accents et ponctuation
 * ignorés, chaque terme borné par des limites de mots (« Durand » ne se
 * trouve pas dans « Durandal »). Sont cherchés :
 *
 *  - le nom complet, et chacun de ses mots d'au moins
 *    {@see self::MIN_NAME_PART_LENGTH} lettres (un nom de famille seul nomme
 *    déjà ; « de », « Le » ne nomment personne) ;
 *  - chaque suite de {@see self::CV_FRAGMENT_WORDS} mots consécutifs du CV.
 */
final readonly class AuthorMentions
{
    private const int MIN_NAME_PART_LENGTH = 3;

    /** Assez long pour qu'une rencontre fortuite avec une description soit invraisemblable. */
    private const int CV_FRAGMENT_WORDS = 6;

    /**
     * @param list<string> $nameTerms   termes normalisés, entourés d'espaces
     * @param list<string> $cvFragments idem
     */
    private function __construct(
        private array $nameTerms,
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
        foreach ($nameWords as $word) {
            if (mb_strlen($word) >= self::MIN_NAME_PART_LENGTH) {
                $nameTerms[] = self::term([$word]);
            }
        }

        $cvWords = self::words($cvText ?? '');
        $cvFragments = [];
        $wordCount = \count($cvWords);
        for ($start = 0; $start + self::CV_FRAGMENT_WORDS <= $wordCount; ++$start) {
            $cvFragments[] = self::term(\array_slice($cvWords, $start, self::CV_FRAGMENT_WORDS));
        }

        return new self(array_values(array_unique($nameTerms)), array_values(array_unique($cvFragments)));
    }

    /** Nombre de fragments du CV cherchés : zéro quand le CV de test n'a pas de texte. */
    public function cvFragmentCount(): int
    {
        return \count($this->cvFragments);
    }

    /**
     * Les endroits de `$published` qui nomment l'auteur.
     *
     * Chaque chaîne est lue, clés comprises (un nom de propriété de schéma est
     * publié lui aussi). Une exception admise par l'ADR 0006 sous D8 n'est
     * admise qu'à son chemin et pour sa valeur exacte : la même valeur
     * ailleurs, ou une autre valeur à ce chemin, est lue comme le reste.
     *
     * @param array<mixed>          $published  ce que le serveur publie, par source
     * @param array<string, string> $exceptions chemin => valeur admise
     *
     * @return list<string> « chemin : terme » sans le terme, triés
     */
    public function in(array $published, array $exceptions = []): array
    {
        $violations = [];
        foreach (self::strings($published, '') as [$path, $text]) {
            if (($exceptions[$path] ?? null) === $text) {
                continue;
            }

            $haystack = self::term(self::words($text));
            foreach ($this->nameTerms as $index => $term) {
                if (str_contains($haystack, $term)) {
                    $violations[] = \sprintf('%s : nom de l\'auteur (terme n°%d)', $path, $index);
                }
            }
            foreach ($this->cvFragments as $index => $fragment) {
                if (str_contains($haystack, $fragment)) {
                    $violations[] = \sprintf('%s : fragment n°%d du CV nominatif', $path, $index);
                }
            }
        }
        sort($violations);

        return $violations;
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
     * Les mots d'un texte, en minuscules et sans accents.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
        $withoutMarks = (string) preg_replace('/\p{Mn}+/u', '', false === $decomposed ? $text : $decomposed);

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($withoutMarks), -1, \PREG_SPLIT_NO_EMPTY);

        return false === $words ? [] : $words;
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
