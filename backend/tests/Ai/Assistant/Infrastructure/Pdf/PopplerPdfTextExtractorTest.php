<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Pdf;

use App\Ai\Assistant\Infrastructure\Pdf\CvTextExtractionException;
use App\Ai\Assistant\Infrastructure\Pdf\ExtractedTextNormalizer;
use App\Ai\Assistant\Infrastructure\Pdf\PopplerPdfTextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extraction du CV nominatif par le vrai pdftotext (spec 0005 D7, M2) sur un
 * PDF de fixture fictif : deux pages, en-tête et pied répétés, numéro de page
 * (source et commande de régénération dans Fixtures/cv-fictif.html).
 *
 * Les assertions portent sur les critères de l'issue #263 plutôt que sur une
 * chaîne exacte : la CI tourne avec le poppler d'Ubuntu, l'image avec celui
 * d'Alpine, et seule la structure doit être garantie par les deux.
 */
final class PopplerPdfTextExtractorTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/Fixtures/cv-fictif.pdf';
    private const string EMPTY_PDF = __DIR__.'/../../../../Portfolio/Cv/Fixtures/dummy.pdf';

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/cv-extractor-'.bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        foreach (new \FilesystemIterator($this->workDirectory) as $file) {
            unlink((string) $file);
        }
        rmdir($this->workDirectory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paragraphsOfTheFixture(): iterable
    {
        yield 'page 1, sur trois lignes' => ['Développeuse PHP depuis douze ans, spécialisée dans les applications Symfony à fort volume et dans la reprise de bases de code anciennes.'];
        yield 'page 1, sous un titre' => ["Société Fictive, Lyon, 2019 à 2026 : refonte d'une plateforme de réservation, migration de Symfony 4 vers Symfony 7 sans interruption de service et division par trois du temps de réponse médian."];
        yield 'page 2' => ["Master d'informatique, Université Imaginaire, 2014 : mémoire consacré aux files de messages et à la reprise sur incident des traitements asynchrones."];
        yield 'page 2, élément de liste' => ['PHP et Symfony, de la version 2 à la version 8, avec une attention particulière aux migrations progressives.'];
        yield 'page 2, dernier paragraphe' => ['Escalade, cuisine japonaise et contributions occasionnelles à des bibliothèques libres.'];
    }

    /**
     * Chaque paragraphe une fois, en entier sur sa ligne : aucun retour à la
     * ligne de la mise en page n'a survécu.
     */
    #[DataProvider('paragraphsOfTheFixture')]
    public function testEachParagraphAppearsOnceOnItsOwnLine(string $paragraph): void
    {
        $lines = explode("\n", $this->extractFixture());

        self::assertCount(1, array_keys($lines, $paragraph, true));
    }

    public function testTheRepeatedHeaderAndFooterAppearOnlyOnce(): void
    {
        $text = $this->extractFixture();

        self::assertSame(1, substr_count($text, 'Camille Exemple — Développeuse PHP'));
        self::assertSame(1, substr_count($text, 'camille.exemple@example.test'));
        self::assertStringNotContainsString('Page 2 / 2', $text);
    }

    /** L'assistant désigne le titulaire par son prénom (journal de la spec). */
    public function testTheFirstNameSurvivesTheExtraction(): void
    {
        self::assertContains('Camille Exemple', explode("\n", $this->extractFixture()));
    }

    public function testAHeadingStaysOnItsOwnLine(): void
    {
        $lines = explode("\n", $this->extractFixture());

        foreach (['Expérience', 'Formation', 'Compétences', "Centres d'intérêt"] as $heading) {
            self::assertContains($heading, $lines);
        }
    }

    /** Fichier absent : ce n'est pas une erreur, le corpus le dira. */
    public function testAMissingFileGivesNull(): void
    {
        self::assertNull($this->extractor($this->workDirectory.'/absent.pdf')->extract());
    }

    /**
     * Aucun cache (D7) : le texte suit le fichier dès l'appel suivant, avec la
     * même instance — celle que le conteneur partage entre les requêtes d'un
     * worker FPM.
     */
    public function testReplacingTheFileChangesTheNextExtraction(): void
    {
        $path = $this->workDirectory.'/cv.pdf';
        $extractor = $this->extractor($path);

        copy(self::FIXTURE, $path);
        self::assertStringContainsString('Camille Exemple', (string) $extractor->extract());

        copy(self::EMPTY_PDF, $path);
        self::assertSame('', $extractor->extract());
    }

    /**
     * Le message est littéral et rien n'est chaîné : l'exception du processus
     * recopie sa sortie, donc le texte du CV, et le noyau journalise toute la
     * chaîne (D10).
     */
    public function testAnUnreadableFileFailsWithoutCarryingTheProcessOutput(): void
    {
        $path = $this->workDirectory.'/cv.pdf';
        file_put_contents($path, "Ce n'est pas un PDF : Camille Exemple.");

        try {
            $this->extractor($path)->extract();
            self::fail('Un fichier illisible doit lever une exception.');
        } catch (CvTextExtractionException $exception) {
            self::assertSame('Extraction du texte du CV impossible.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private function extractFixture(): string
    {
        $text = $this->extractor(self::FIXTURE)->extract();
        self::assertNotNull($text);

        return $text;
    }

    private function extractor(string $path): PopplerPdfTextExtractor
    {
        return new PopplerPdfTextExtractor($path, new ExtractedTextNormalizer());
    }
}
