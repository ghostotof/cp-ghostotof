<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Presentation\Controller;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Presentation\Controller\AnswerController;
use App\Ai\Assistant\Presentation\Dto\AnswerRequest;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #318 : une fois le 200 envoyé, le flux se termine toujours par un
 * événement final, quelle que soit l'exception levée en cours de route.
 *
 * Test unitaire et non fonctionnel : aucune réponse du transport simulé ne
 * fait lever au vrai bridge une exception hors de celles que
 * SymfonyAiCareerAssistant convertit déjà. L'assistant est donc remplacé par
 * un générateur qui lève ce qu'on veut, au moment voulu.
 */
final class AnswerControllerStreamEndTest extends TestCase
{
    /** Message d'exception qu'aucune sortie ne doit porter (D10). */
    private const string SECRET = 'corps de réponse du fournisseur, sk-secret';

    /**
     * @return iterable<string, array{\Closure(): \Generator<int, string, mixed, AnswerUsage>, class-string<\Throwable>}>
     */
    public static function failingStreams(): iterable
    {
        // Une exception du bridge que l'assistant ne relaie pas.
        yield 'ValueError du bridge' => [
            static function (): \Generator {
                yield 'Il a ';

                throw new \ValueError(self::SECRET);
            },
            \ValueError::class,
        ];

        // Le json_encode du contrôleur lui-même : un octet UTF-8 invalide dans
        // un fragment lève \JsonException (JSON_THROW_ON_ERROR).
        yield 'fragment illisible en JSON' => [
            static function (): \Generator {
                yield 'Il a ';
                yield "\xB1";

                return new AnswerUsage(1, 1, 1);
            },
            \JsonException::class,
        ];
    }

    /**
     * Les flux de failingStreams(), sans la classe attendue : seul le test du
     * journal en a l'usage.
     *
     * @return iterable<string, array{\Closure(): \Generator<int, string, mixed, AnswerUsage>}>
     */
    public static function failingStreamsOnly(): iterable
    {
        foreach (self::failingStreams() as $name => [$stream]) {
            yield $name => [$stream];
        }
    }

    /**
     * @param \Closure(): \Generator<int, string, mixed, AnswerUsage> $stream
     */
    #[DataProvider('failingStreamsOnly')]
    public function testAnyExceptionAfterADeltaEndsWithAnErrorEvent(\Closure $stream): void
    {
        $handler = new TestHandler();

        $output = $this->send($stream, $handler);

        self::assertSame(
            "event: delta\ndata: {\"text\":\"Il a \"}\n\n"
            ."event: error\ndata: {\"reason\":\"assistant-unavailable\"}\n\n",
            $output,
        );
        self::assertStringNotContainsString(self::SECRET, $output);
    }

    /**
     * D10 : la fin du flux est journalisée sur `ai_usage`, avec la classe de
     * l'exception et jamais son message.
     *
     * @param \Closure(): \Generator<int, string, mixed, AnswerUsage> $stream
     * @param class-string<\Throwable>                                $expectedException
     */
    #[DataProvider('failingStreams')]
    public function testAnyExceptionAfterADeltaLogsAnErrorOutcomeWithoutItsMessage(\Closure $stream, string $expectedException): void
    {
        $handler = new TestHandler();

        $this->send($stream, $handler);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]->context['outcome'] ?? null);
        self::assertSame('during-stream', $records[0]->context['stage'] ?? null);
        self::assertSame($expectedException, $records[0]->context['exception'] ?? null);
        $line = $records[0]->message.json_encode($records[0]->context, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $line);
        self::assertStringNotContainsString('Il a', $line);
    }

    /**
     * D10 : la ligne de fin porte jetons, durée et nombre de messages, comme
     * celles de l'assistant. Les jetons sont inconnus ici : null explicite,
     * pour qu'une requête sur ces clés ne confonde pas « inconnu » et « absent ».
     */
    public function testTheErrorOutcomeCarriesTheUsageFieldsOfD10(): void
    {
        $handler = new TestHandler();

        $this->send(static function (): \Generator {
            yield 'Il a ';

            throw new \ValueError(self::SECRET);
        }, $handler);

        $context = $handler->getRecords()[0]->context;
        self::assertSame(3, $context['messageCount'] ?? null);
        self::assertIsInt($context['durationMs'] ?? null);
        self::assertGreaterThanOrEqual(0, $context['durationMs']);
        self::assertArrayHasKey('promptTokens', $context);
        self::assertNull($context['promptTokens']);
        self::assertArrayHasKey('completionTokens', $context);
        self::assertNull($context['completionTokens']);
    }

    /**
     * L'assistant journalise déjà ses propres échecs : le contrôleur n'en
     * écrit pas une seconde ligne, qui doublerait les fins sur `ai_usage`.
     */
    public function testAnAssistantUnavailableExceptionEndsWithAnErrorEventAndNoSecondLogLine(): void
    {
        $handler = new TestHandler();

        $output = $this->send(static function (): \Generator {
            yield 'Il a ';

            throw new AssistantUnavailableException();
        }, $handler);

        self::assertSame(
            "event: delta\ndata: {\"text\":\"Il a \"}\n\n"
            ."event: error\ndata: {\"reason\":\"assistant-unavailable\"}\n\n",
            $output,
        );
        self::assertSame([], $handler->getRecords());
    }

    /**
     * Une exception levée avant tout fragment, une fois le flux ouvert, se
     * termine elle aussi par `error`, seul événement du flux.
     */
    public function testAnExceptionBeforeAnyDeltaEndsWithAnErrorEventAlone(): void
    {
        $handler = new TestHandler();

        $output = $this->send(static function (): \Generator {
            yield from self::failingBeforeAnyFragment();

            return new AnswerUsage(1, 1, 1);
        }, $handler);

        self::assertSame("event: error\ndata: {\"reason\":\"assistant-unavailable\"}\n\n", $output);
        self::assertCount(1, $handler->getRecords());
    }

    /**
     * Lève dès la première itération : le générateur appelant n'émet rien.
     *
     * @return iterable<int, string>
     */
    private static function failingBeforeAnyFragment(): iterable
    {
        throw new \ValueError(self::SECRET);
    }

    /**
     * @param \Closure(): \Generator<int, string, mixed, AnswerUsage> $stream
     */
    private function send(\Closure $stream, TestHandler $handler): string
    {
        $assistant = new readonly class($stream) implements CareerAssistantInterface {
            /** @param \Closure(): \Generator<int, string, mixed, AnswerUsage> $stream */
            public function __construct(private \Closure $stream)
            {
            }

            public function answer(Conversation $conversation, Locale $locale): \Generator
            {
                return ($this->stream)();
            }
        };
        $controller = new AnswerController($assistant, new Logger('ai_usage', [$handler]));

        $response = $controller(new AnswerRequest('fr', [
            ['role' => 'user', 'content' => 'Quel est son domaine ?'],
            ['role' => 'assistant', 'content' => "L'architecture logicielle."],
            ['role' => 'user', 'content' => 'Depuis combien de temps ?'],
        ]));

        ob_start();
        try {
            $response->sendContent();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
