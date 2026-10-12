<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Pdf;

use App\Ai\Assistant\Infrastructure\Pdf\ExtractedTextNormalizer;
use App\Ai\Assistant\Infrastructure\Pdf\PopplerPdfTextExtractor;
use FilesystemIterator;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extraction du CV nominatif par le vrai pdftotext (spec 0005 D7, M2) sur un
 * PDF de fixture fictif : deux pages, en-tête et pied répétés, numéro de page
 * (source et commande de régénération dans Fixtures/cv-fictif.html).
 *
 * Les assertions portent sur les critères de l'issue #263 plutôt que sur une
 * chaîne exacte : la CI tourne avec le poppler d'Ubuntu, l'image avec celui
 * d'Alpine, et seule la structure doit être garantie par les deux. Les cas
 * limites (échec, sortie trop longue, environnement) passent par un faux
 * binaire, un script shell écrit pour le test.
 */
final class PopplerPdfTextExtractorTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/Fixtures/cv-fictif.pdf';
    private const string EMPTY_PDF = __DIR__.'/../../../../Portfolio/Cv/Fixtures/dummy.pdf';

    private string $workDirectory;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/cv-extractor-'.bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
        $this->logHandler = new TestHandler();
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->workDirectory) as $file) {
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

    public function testTheRepeatedHeaderAndFooterAppearOnlyOnceAndPageNumbersNotAtAll(): void
    {
        $text = $this->extractFixture();

        self::assertSame(1, substr_count($text, 'Camille Exemple — Développeuse PHP'));
        self::assertSame(1, substr_count($text, 'camille.exemple@example.test'));
        self::assertStringNotContainsString('Page 1 / 2', $text);
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
    public function testAMissingFileGivesNullWithoutAWarning(): void
    {
        self::assertNull($this->extractor($this->workDirectory.'/absent.pdf')->extract());
        self::assertSame([], $this->logHandler->getRecords());
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
        self::assertNull($extractor->extract());
    }

    /**
     * Un PDF sans couche texte (un scan) se dit indisponible, et le dit au
     * journal : sinon un CV scanné en production ne se verrait qu'à l'absence
     * de réponses.
     */
    public function testAPdfWithoutTextGivesNullAndAWarning(): void
    {
        self::assertNull($this->extractor(self::EMPTY_PDF)->extract());

        self::assertSame('no-text', $this->singleWarning()->context['reason'] ?? null);
    }

    /**
     * Revue de branche (P5, C3) : un CV illisible ne coupe plus l'assistant —
     * la section se dit indisponible — et l'échec se diagnostique : un
     * `warning` nomme la cause, jamais le contenu. Le faux binaire écrit un
     * fragment sur stdout puis échoue : c'est ce que ProcessFailedException
     * recopierait dans son message.
     */
    public function testAFailingExtractionGivesNullAndAWarningThatNamesTheCauseNotTheText(): void
    {
        $binary = $this->fakeBinary("echo 'Université Imaginaire'\necho 'Escalade' >&2\nexit 3");

        self::assertNull($this->extractor(self::FIXTURE, $binary)->extract());

        $record = $this->singleWarning();
        self::assertSame('Extraction du CV nominatif impossible : section déclarée indisponible.', $record->message);
        self::assertSame('extraction-failed', $record->context['reason'] ?? null);
        self::assertSame(3, $record->context['exitCode'] ?? null);
        $this->assertLogsCarryNothingOf(['Université Imaginaire', 'Escalade']);
    }

    public function testAnUnreadableFileGivesNullAndAWarning(): void
    {
        $path = $this->workDirectory.'/cv.pdf';
        file_put_contents($path, "Ce n'est pas un PDF : Université Imaginaire.");

        self::assertNull($this->extractor($path)->extract());

        self::assertSame('extraction-failed', $this->singleWarning()->context['reason'] ?? null);
        $this->assertLogsCarryNothingOf(['Université Imaginaire']);
    }

    public function testAMissingBinaryGivesNullAndAWarningThatSaysSo(): void
    {
        self::assertNull($this->extractor(self::FIXTURE, $this->workDirectory.'/absent-pdftotext')->extract());

        self::assertSame('binary-missing', $this->singleWarning()->context['reason'] ?? null);
    }

    /**
     * Revue de branche (C9) : le texte entre en entier dans chaque appel
     * facturé, le coût doit rester borné par construction (ADR 0004). Au-delà,
     * la section est indisponible plutôt que tronquée : un CV coupé en silence
     * ferait mentir le corpus.
     */
    public function testATextBeyondTheBoundGivesNullAndAWarningWithItsLength(): void
    {
        $binary = $this->fakeBinary('head -c '.(PopplerPdfTextExtractor::MAX_CHARACTERS + 1).' /dev/zero | tr "\\000" a');

        self::assertNull($this->extractor(self::FIXTURE, $binary)->extract());

        $record = $this->singleWarning();
        self::assertSame('too-long', $record->context['reason'] ?? null);
        self::assertSame(PopplerPdfTextExtractor::MAX_CHARACTERS + 1, $record->context['characters'] ?? null);
    }

    public function testATextAtTheBoundIsKept(): void
    {
        $binary = $this->fakeBinary('head -c '.PopplerPdfTextExtractor::MAX_CHARACTERS.' /dev/zero | tr "\\000" a');

        self::assertSame(PopplerPdfTextExtractor::MAX_CHARACTERS, mb_strlen((string) $this->extractor(self::FIXTURE, $binary)->extract()));
    }

    /**
     * Revue de branche (C7) : un parseur PDF écrit en C ne reçoit aucun des
     * secrets du worker. Symfony Process lui passerait sinon tout son
     * environnement — DATABASE_URL, APP_SECRET, clés d'API.
     */
    public function testTheBinaryRunsWithoutTheWorkerEnvironment(): void
    {
        $_ENV['CV_EXTRACTOR_TEST_SECRET'] = 'sentinelle';
        $_SERVER['CV_EXTRACTOR_TEST_SECRET'] = 'sentinelle';
        putenv('CV_EXTRACTOR_TEST_SECRET=sentinelle');
        try {
            $output = (string) $this->extractor(self::FIXTURE, $this->fakeBinary('env'))->extract();
        } finally {
            unset($_ENV['CV_EXTRACTOR_TEST_SECRET'], $_SERVER['CV_EXTRACTOR_TEST_SECRET']);
            putenv('CV_EXTRACTOR_TEST_SECRET');
        }

        self::assertStringNotContainsString('sentinelle', $output);
        self::assertStringNotContainsString('DATABASE_URL', $output);
    }

    private function extractFixture(): string
    {
        $text = $this->extractor(self::FIXTURE)->extract();
        self::assertNotNull($text);

        return $text;
    }

    private function extractor(string $path, string $binary = PopplerPdfTextExtractor::DEFAULT_BINARY): PopplerPdfTextExtractor
    {
        return new PopplerPdfTextExtractor($path, new ExtractedTextNormalizer(), new Logger('test', [$this->logHandler]), $binary);
    }

    /** Un script shell exécutable qui se fait passer pour pdftotext. */
    private function fakeBinary(string $body): string
    {
        $path = $this->workDirectory.'/fake-pdftotext';
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0o700);

        return $path;
    }

    private function singleWarning(): LogRecord
    {
        $records = $this->logHandler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);

        return $records[0];
    }

    /**
     * @param list<string> $fragments
     */
    private function assertLogsCarryNothingOf(array $fragments): void
    {
        foreach ($this->logHandler->getRecords() as $record) {
            $line = $record->message.json_encode($record->context, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach ($fragments as $fragment) {
                self::assertStringNotContainsString($fragment, $line);
            }
        }
    }
}
