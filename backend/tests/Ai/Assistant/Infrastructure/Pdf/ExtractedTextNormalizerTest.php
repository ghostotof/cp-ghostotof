<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Pdf;

use App\Ai\Assistant\Infrastructure\Pdf\CvTextExtractionException;
use App\Ai\Assistant\Infrastructure\Pdf\ExtractedTextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalisation du texte que pdftotext tire du CV nominatif (spec 0005 D7).
 * Les entrées reproduisent la forme de sa sortie : pages séparées par un saut
 * de page (\f), paragraphes par une ligne vide, lignes coupées par la mise en
 * page. Contenu fictif.
 */
final class ExtractedTextNormalizerTest extends TestCase
{
    public function testLinesWrappedByTheLayoutAreJoinedIntoOneParagraph(): void
    {
        $text = "Développeuse PHP depuis douze ans, spécialisée dans les\n"
            ."applications Symfony à fort volume et dans la reprise de bases de\n"
            .'code anciennes.';

        self::assertSame(
            'Développeuse PHP depuis douze ans, spécialisée dans les applications Symfony à fort volume et dans la reprise de bases de code anciennes.',
            $this->normalize($text),
        );
    }

    public function testABlankLineSeparatesTwoParagraphs(): void
    {
        self::assertSame("Premier paragraphe.\n\nSecond paragraphe.", $this->normalize("Premier paragraphe.\n\n\n\nSecond paragraphe."));
    }

    /**
     * pdftotext ne sépare pas un titre de son paragraphe : une ligne courte
     * n'est jamais recollée, sans quoi le titre deviendrait le début de la
     * phrase qui suit.
     */
    public function testAShortLineIsKeptOnItsOwnLine(): void
    {
        $text = "Expérience\n"
            ."Société Fictive, Lyon, 2019 à 2026 : refonte d'une plateforme de\n"
            .'réservation.';

        self::assertSame(
            "Expérience\nSociété Fictive, Lyon, 2019 à 2026 : refonte d'une plateforme de réservation.",
            $this->normalize($text),
        );
    }

    /**
     * Les puces d'une liste ne survivent pas toujours à l'extraction : un
     * élément qui finit une phrase n'avale pas l'élément suivant.
     */
    public function testALineEndingASentenceIsNotJoinedToTheNextOne(): void
    {
        $text = "PHP et Symfony, de la version 2 à la version 8, avec une\n"
            ."attention particulière aux migrations progressives.\n"
            ."PostgreSQL\n"
            .'RabbitMQ';

        self::assertSame(
            "PHP et Symfony, de la version 2 à la version 8, avec une attention particulière aux migrations progressives.\nPostgreSQL\nRabbitMQ",
            $this->normalize($text),
        );
    }

    public function testSpacesAndTabsAreCompressed(): void
    {
        self::assertSame('Trois mots ici.', $this->normalize("  Trois \t\t mots\u{00A0}\u{00A0}ici.  "));
    }

    public function testControlCharactersAreRemoved(): void
    {
        self::assertSame('Sans contrôle.', $this->normalize("Sans\x00 con\x07trôle\x1B.\x7F"));
    }

    /**
     * Un en-tête répété garde sa première occurrence : supprimé partout, un CV
     * qui ne porte le prénom ou l'adresse qu'en en-tête les perdrait, et
     * l'assistant désigne le titulaire par son prénom (journal de la spec).
     */
    public function testAHeaderRepeatedOnEveryPageIsKeptOnlyOnce(): void
    {
        $text = "Camille Exemple — Développeuse PHP\n\nPage une.\f"
            ."Camille Exemple — Développeuse PHP\n\nPage deux.\f";

        self::assertSame("Camille Exemple — Développeuse PHP\n\nPage une.\n\nPage deux.", $this->normalize($text));
    }

    public function testAFooterRepeatedOnEveryPageIsKeptOnlyOnce(): void
    {
        $text = "Page une.\n\ncamille.exemple@example.test\f"
            ."Page deux.\n\ncamille.exemple@example.test\f";

        self::assertSame("Page une.\n\ncamille.exemple@example.test\n\nPage deux.", $this->normalize($text));
    }

    /**
     * Un numéro de page n'apprend rien au modèle : il disparaît, sous ses
     * formes courantes, mais seulement au bord d'une page.
     *
     * @return iterable<string, array{string}>
     */
    public static function pageNumbers(): iterable
    {
        yield 'barre' => ['Page 1 / 2'];
        yield 'sur' => ['page 1 sur 2'];
        yield 'of' => ['Page 1 of 2'];
        yield 'seul' => ['Page 1'];
        yield 'abrégé' => ['p. 1'];
        yield 'nu avec barre' => ['1/2'];
    }

    #[DataProvider('pageNumbers')]
    public function testAPageNumberAtThePageEdgeIsRemoved(string $pageNumber): void
    {
        self::assertSame('Fin de page.', $this->normalize("Fin de page.\n\n".$pageNumber."\f"));
    }

    /**
     * Revue de branche (C1) : les chiffres comptaient pour rien dans la
     * comparaison des lignes, si bien que deux lignes de dates au bord de deux
     * pages passaient pour un même pied — la seconde disparaissait.
     */
    public function testDateLinesAtThePageEdgesAreAllKept(): void
    {
        $text = "Intro.\n\nDéveloppeur Java, Société B\n2015 – 2019\f"
            ."Stagiaire, Société C\n2014 – 2015\n\nSuite.";

        $normalized = $this->normalize($text);

        self::assertStringContainsString('2015 – 2019', $normalized);
        self::assertStringContainsString('2014 – 2015', $normalized);
    }

    /**
     * Seuls les bords d'une page portent un en-tête ou un pied : une ligne
     * répétée au milieu du texte est du contenu.
     */
    public function testALineRepeatedOutsideThePageEdgesIsContent(): void
    {
        $page = "Début.\n\nDeux.\n\nTrois.\n\nSymfony\n\nCinq.\n\nSix.\n\nFin.";

        self::assertSame(2, substr_count($this->normalize($page."\f".$page), 'Symfony'));
    }

    /** Sur une seule page, rien ne se répète d'une page à l'autre. */
    public function testASinglePageLosesNoLine(): void
    {
        self::assertSame("Camille Exemple\n\ncamille.exemple@example.test", $this->normalize("Camille Exemple\n\ncamille.exemple@example.test\f"));
    }

    /**
     * Revue de branche (C2) : sans plancher absolu, la plus longue d'une
     * suite de lignes courtes « remplissait sa colonne » et avalait la
     * suivante — un titre se collait au premier élément de sa liste.
     *
     * @return iterable<string, array{string}>
     */
    public static function blocksOfShortLines(): iterable
    {
        yield 'titre et liste' => ["Compétences\nPHP\nSymfony\nDocker"];
        yield 'coordonnées' => ["Camille Exemple\nLyon, France\n06 12 34 56 78\ncamille@example.test"];
        yield 'poste et dates' => ["Développeur PHP, Société A\n2019 – 2021"];
    }

    #[DataProvider('blocksOfShortLines')]
    public function testShortLinesAreNeverJoined(string $block): void
    {
        self::assertSame($block, $this->normalize($block));
    }

    /** Revue de branche (C6) : une phrase finit aussi derrière un guillemet ou une parenthèse. */
    public function testASentenceClosedByAQuoteOrAParenthesisIsNotJoined(): void
    {
        $text = "Il a écrit « la migration se fait par étapes, sans interruption de service. »\n"
            ."PostgreSQL\n\n"
            ."Plusieurs chantiers de reprise de code ont été menés (voir les études de cas.)\n"
            .'RabbitMQ';

        self::assertSame(
            "Il a écrit « la migration se fait par étapes, sans interruption de service. »\nPostgreSQL\n\n"
            ."Plusieurs chantiers de reprise de code ont été menés (voir les études de cas.)\nRabbitMQ",
            $this->normalize($text),
        );
    }

    /**
     * Revue de branche (S1) : un UTF-8 invalide fait échouer PCRE ; un texte
     * vidé en silence ferait dire à l'assistant que le CV ne mentionne rien.
     */
    public function testInvalidUtf8IsAnErrorNotASilentlyEmptyText(): void
    {
        $this->expectException(CvTextExtractionException::class);

        $this->normalize("Texte \xC3( invalide.");
    }

    public function testAPageBreakSeparatesParagraphs(): void
    {
        self::assertSame("Fin de page.\n\nDébut de page.", $this->normalize("Fin de page.\fDébut de page."));
    }

    public function testAnEmptyExtractionGivesAnEmptyText(): void
    {
        self::assertSame('', $this->normalize(" \n\f\n \f"));
    }

    private function normalize(string $text): string
    {
        return (new ExtractedTextNormalizer())->normalize($text);
    }
}
