<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Application;

use App\Ai\Assistant\Application\AssistantPreambleMissingException;
use App\Ai\Assistant\Application\CareerAssistantSystemPrompt;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Ai\Assistant\Support\StubCorpusRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Message système = préambule du fichier de prompt, puis corpus (spec 0005 D8).
 * Le vrai fichier de prompt est lu : c'est lui qui part en production.
 */
final class CareerAssistantSystemPromptTest extends TestCase
{
    private const string PREAMBLE_FILE = __DIR__.'/../../../../config/ai/prompts/career_assistant.txt';

    public function testThePreambleComesFirstThenTheCorpusOfTheRequestedLocale(): void
    {
        $renderer = new StubCorpusRenderer();

        $prompt = (new CareerAssistantSystemPrompt($renderer, self::PREAMBLE_FILE))->compose(Locale::EN);

        $preamble = rtrim((string) file_get_contents(self::PREAMBLE_FILE));
        self::assertStringStartsWith('You are the career assistant', $preamble);
        self::assertSame($preamble."\n\n<documents>\n\nCORPUS\n\n</documents>\n", $prompt);
        self::assertSame(Locale::EN, $renderer->lastLocale);
    }

    public function testAMissingPreambleIsADeploymentBugNotAnEmptyPrompt(): void
    {
        $this->expectException(AssistantPreambleMissingException::class);

        (new CareerAssistantSystemPrompt(new StubCorpusRenderer(), '/nonexistent/prompt.txt'))->compose(Locale::FR);
    }

    /**
     * Un fichier présent mais blanc ferait partir un message système réduit au
     * seul corpus, sans les consignes : le même défaut que le fichier absent.
     */
    public function testABlankPreambleIsADeploymentBugToo(): void
    {
        $blankFile = tempnam(sys_get_temp_dir(), 'preamble');
        self::assertIsString($blankFile);
        file_put_contents($blankFile, " \n\t\n");

        try {
            $this->expectException(AssistantPreambleMissingException::class);

            (new CareerAssistantSystemPrompt(new StubCorpusRenderer(), $blankFile))->compose(Locale::FR);
        } finally {
            unlink($blankFile);
        }
    }
}
