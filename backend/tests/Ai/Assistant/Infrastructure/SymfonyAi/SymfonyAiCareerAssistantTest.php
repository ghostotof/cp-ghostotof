<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\SymfonyAi;

use App\Ai\Assistant\Application\CareerAssistantSystemPrompt;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\ConversationMessage;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Ai\Assistant\Infrastructure\SymfonyAi\SymfonyAiCareerAssistant;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Ai\Assistant\Support\StubCorpusRenderer;
use App\Tests\Ai\Support\FakeStreamingAgent;
use App\Tests\Ai\Translation\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * L'assistant en flux (spec 0005 M3, hors bornes et quota). Un agent de test
 * paresseux remplace le vrai : aucun appel ne sort.
 */
final class SymfonyAiCareerAssistantTest extends TestCase
{
    private const string PREAMBLE_FILE = __DIR__.'/../../../../../config/ai/prompts/career_assistant.txt';
    private const string QUESTION = 'Question sentinelle sur le parcours ?';
    private const string PREVIOUS_ANSWER = 'Réponse sentinelle précédente.';
    private const string CORPUS = "<documents>\n\nCORPUS-SENTINELLE\n\n</documents>\n";

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
    }

    public function testFragmentsFormTheAnswerAndTheReturnValueCarriesTheUsage(): void
    {
        $agent = new FakeStreamingAgent(['Il a ', 'conçu ', 'des API.'], new TokenUsage(promptTokens: 812, completionTokens: 9));

        $stream = $this->assistant($agent)->answer($this->conversation(), Locale::FR);
        $answer = implode('', iterator_to_array($stream, false));

        self::assertSame('Il a conçu des API.', $answer);
        $usage = $stream->getReturn();
        self::assertSame(812, $usage->promptTokens);
        self::assertSame(9, $usage->completionTokens);
        self::assertGreaterThanOrEqual(0, $usage->durationMs);
    }

    public function testTheCallIsStreamedAndAsksForTheUsage(): void
    {
        $agent = new FakeStreamingAgent(['ok']);

        iterator_to_array($this->assistant($agent)->answer($this->conversation(), Locale::FR), false);

        self::assertTrue($agent->lastOptions['stream'] ?? null);
        self::assertSame(['include_usage' => true], $agent->lastOptions['stream_options'] ?? null);
    }

    public function testSystemMessageIsPreamblePlusCorpusThenTheConversationInOrder(): void
    {
        $agent = new FakeStreamingAgent(['ok']);

        iterator_to_array($this->assistant($agent)->answer($this->conversation(), Locale::FR), false);

        $messages = ($agent->lastMessages ?? self::fail('Aucun appel enregistré.'))->getMessages();
        self::assertCount(4, $messages);
        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        $system = $messages[0]->getContent();
        self::assertIsString($system);
        self::assertStringStartsWith('You are the career assistant', $system);
        self::assertStringEndsWith(self::CORPUS, $system);
        self::assertInstanceOf(UserMessage::class, $messages[1]);
        self::assertInstanceOf(AssistantMessage::class, $messages[2]);
        self::assertSame(self::PREVIOUS_ANSWER, $messages[2]->asText());
        self::assertInstanceOf(UserMessage::class, $messages[3]);
        self::assertSame(self::QUESTION, $messages[3]->asText());
    }

    /** L'appel part avant que answer() ne rende la main : le 503 est encore possible. */
    public function testAFailureBeforeTheFirstFragmentIsThrownByAnswerItself(): void
    {
        $agent = new FakeStreamingAgent([], failure: new ServerException(502, 'corps du fournisseur'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
            self::fail('AssistantUnavailableException attendue.');
        } catch (AssistantUnavailableException) {
        }

        $record = $this->logger->records[0] ?? self::fail('Aucun log.');
        self::assertSame('error', $record['level']);
        self::assertSame('before-first-fragment', $record['context']['stage']);
        self::assertSame(502, $record['context']['providerStatus']);
    }

    /** Journal de la tâche 1 : le statut HTTP d'un refus en flux doit rester lisible. */
    public function testTheStatusOfAStreamedProviderRefusalIsLogged(): void
    {
        $agent = new FakeStreamingAgent([], failure: new PlatformRuntimeException('Unexpected response code 403: "{\"message\":\"refus\"}"'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
            self::fail('AssistantUnavailableException attendue.');
        } catch (AssistantUnavailableException) {
        }

        self::assertSame(403, $this->logger->records[0]['context']['providerStatus'] ?? null);
    }

    public function testAFailureDuringTheStreamIsThrownByTheGeneratorAfterTheFirstFragments(): void
    {
        $agent = new FakeStreamingAgent(['Il a ', 'conçu'], failure: new TransportException('coupure'), failAfter: 1);
        $stream = $this->assistant($agent)->answer($this->conversation(), Locale::FR);

        [$received, $failure] = $this->consume($stream);

        self::assertSame(['Il a '], $received);
        self::assertInstanceOf(AssistantUnavailableException::class, $failure);
        self::assertSame('during-stream', $this->logger->records[0]['context']['stage'] ?? null);
    }

    /**
     * Le bridge décode chaque ligne SSE avec JSON_THROW_ON_ERROR : une ligne
     * tronquée par le fournisseur ou un proxy est une panne du fournisseur,
     * pas un 500.
     */
    public function testAMalformedProviderLineBeforeTheFirstFragmentIsUnavailable(): void
    {
        $agent = new FakeStreamingAgent([], failure: new \JsonException('Syntax error'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
            self::fail('AssistantUnavailableException attendue.');
        } catch (AssistantUnavailableException) {
        }

        self::assertSame('before-first-fragment', $this->logger->records[0]['context']['stage'] ?? null);
    }

    public function testAMalformedProviderLineDuringTheStreamIsUnavailable(): void
    {
        $agent = new FakeStreamingAgent(['Il a ', 'conçu'], failure: new \JsonException('Syntax error'), failAfter: 1);
        $stream = $this->assistant($agent)->answer($this->conversation(), Locale::FR);

        [$received, $failure] = $this->consume($stream);

        self::assertSame(['Il a '], $received);
        self::assertInstanceOf(AssistantUnavailableException::class, $failure);
        self::assertSame('during-stream', $this->logger->records[0]['context']['stage'] ?? null);
    }

    /** Point de relecture n°2 : aucun fragment est une réponse vide, pas une panne. */
    public function testAnAnswerWithoutAnyTextFragmentEndsNormally(): void
    {
        $stream = $this->assistant(new FakeStreamingAgent([]))->answer($this->conversation(), Locale::FR);

        self::assertSame([], iterator_to_array($stream, false));
        self::assertNull($stream->getReturn()->promptTokens);
    }

    public function testUsageIsLoggedAfterTheStreamAndNeverAnyContent(): void
    {
        $agent = new FakeStreamingAgent(['Fragment ', 'SENTINELLE-REPONSE'], new TokenUsage(promptTokens: 10, completionTokens: 2));
        $stream = $this->assistant($agent)->answer($this->conversation(), Locale::FR);

        self::assertSame('[]', $this->logger->dump(), 'Rien ne doit être journalisé avant la fin du flux.');
        iterator_to_array($stream, false);

        $record = $this->logger->records[0] ?? self::fail('Aucun log.');
        self::assertSame('info', $record['level']);
        self::assertSame('done', $record['context']['outcome']);
        self::assertSame(3, $record['context']['messageCount']);
        self::assertSame(10, $record['context']['promptTokens']);
        self::assertSame(2, $record['context']['completionTokens']);

        $dump = $this->logger->dump();
        foreach (['SENTINELLE-REPONSE', 'Question sentinelle', 'Réponse sentinelle', 'CORPUS-SENTINELLE', 'You are the career assistant'] as $content) {
            self::assertStringNotContainsString($content, $dump);
        }
    }

    public function testNoProviderMessageIsEverLogged(): void
    {
        $agent = new FakeStreamingAgent([], failure: new ServerException(500, 'SENTINELLE-FOURNISSEUR'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
            self::fail('AssistantUnavailableException attendue.');
        } catch (AssistantUnavailableException) {
        }

        self::assertStringNotContainsString('SENTINELLE-FOURNISSEUR', $this->logger->dump());
    }

    /**
     * L'exception qui sort d'ici est journalisée par l'ErrorListener du noyau,
     * chaîne `previous` comprise (formateur JSON en prod) : elle ne doit rien
     * transporter du fournisseur, dont le bridge recopie le corps (D10).
     */
    public function testTheExceptionLeavingTheServiceCarriesNothingFromTheProvider(): void
    {
        $agent = new FakeStreamingAgent([], failure: new PlatformRuntimeException('Unexpected response code 400: "SENTINELLE-FOURNISSEUR"'));

        try {
            $this->assistant($agent)->answer($this->conversation(), Locale::FR);
            self::fail('AssistantUnavailableException attendue.');
        } catch (AssistantUnavailableException $exception) {
            for ($link = $exception; null !== $link; $link = $link->getPrevious()) {
                self::assertStringNotContainsString('SENTINELLE-FOURNISSEUR', $link->getMessage());
            }
        }
    }

    /**
     * Consomme le flux jusqu'au bout ou jusqu'à l'échec, et rend les deux.
     *
     * @param \Generator<int, string, mixed, mixed> $stream
     *
     * @return array{list<string>, ?AssistantUnavailableException}
     */
    private function consume(\Generator $stream): array
    {
        $received = [];
        try {
            foreach ($stream as $fragment) {
                $received[] = $fragment;
            }
        } catch (AssistantUnavailableException $exception) {
            return [$received, $exception];
        }

        return [$received, null];
    }

    private function assistant(FakeStreamingAgent $agent): SymfonyAiCareerAssistant
    {
        return new SymfonyAiCareerAssistant(
            $agent,
            new CareerAssistantSystemPrompt(new StubCorpusRenderer(self::CORPUS), self::PREAMBLE_FILE),
            $this->logger,
        );
    }

    private function conversation(): Conversation
    {
        return new Conversation([
            new ConversationMessage(Role::User, 'Première question ?'),
            new ConversationMessage(Role::Assistant, self::PREVIOUS_ANSWER),
            new ConversationMessage(Role::User, self::QUESTION),
        ]);
    }
}
