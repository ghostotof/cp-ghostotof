<?php

declare(strict_types=1);

namespace App\Tests\Ai\Shared\Infrastructure\SymfonyAi;

use App\Ai\Shared\Infrastructure\SymfonyAi\ProviderFailure;
use App\Ai\Shared\Infrastructure\SymfonyAi\ProviderFailureReason;
use Closure;
use Exception;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Bridge\Anthropic\ResultConverter as AnthropicResultConverter;
use Symfony\AI\Platform\Bridge\Scaleway\Llm\ResultConverter as ScalewayResultConverter;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;
use TypeError;

/**
 * Les exceptions viennent des vrais ResultConverter des deux bridges
 * (symfony/ai-*-platform, à la version épinglée dans composer.json), nourris
 * de réponses simulées : c'est le format réellement levé qui est lu, et une
 * montée de version qui le change fait rougir ce test plutôt que de rendre le
 * journal muet. Aucun appel ne sort.
 *
 * Chaque corps porte une sentinelle : la description ne doit jamais la contenir.
 */
final class ProviderFailureTest extends TestCase
{
    private const string SENTINEL = 'SENTINELLE-FOURNISSEUR';

    /** Arguments d'outil tronqués : ni Anthropic ni le trait Completions ne savent les décoder. */
    private const string MALFORMED_JSON = '{"query": ';

    /**
     * @return iterable<string, array{Closure():Throwable, ?int, ?string, ProviderFailureReason}>
     */
    public static function bridgeFailures(): iterable
    {
        $anthropic = new AnthropicResultConverter();
        $scaleway = new ScalewayResultConverter();

        // Anthropic, hors flux (le traducteur).
        yield 'anthropic 400' => [static fn (): Throwable => self::convert($anthropic, 400, self::anthropicError('invalid_request_error')), null, null, ProviderFailureReason::InputRejected];
        yield 'anthropic 400, entrée trop longue' => [static fn (): Throwable => self::convert($anthropic, 400, self::anthropicError('invalid_request_error', 'prompt is too long: '.self::SENTINEL)), null, null, ProviderFailureReason::InputRejected];
        yield 'anthropic 401' => [static fn (): Throwable => self::convert($anthropic, 401, self::anthropicError('authentication_error')), null, null, ProviderFailureReason::Authentication];
        yield 'anthropic 403, droit retiré' => [static fn (): Throwable => self::convert($anthropic, 403, self::anthropicError('permission_error')), null, 'permission_error', ProviderFailureReason::PermissionDenied];
        yield 'anthropic 404, modèle retiré' => [static fn (): Throwable => self::convert($anthropic, 404, self::anthropicError('not_found_error')), null, 'not_found_error', ProviderFailureReason::ModelNotFound];
        yield 'anthropic 429' => [static fn (): Throwable => self::convert($anthropic, 429, self::anthropicError('rate_limit_error')), null, null, ProviderFailureReason::RateLimited];
        yield 'anthropic surcharge dans un 200' => [static fn (): Throwable => self::convert($anthropic, 200, self::anthropicError('overloaded_error')), null, 'overloaded_error', ProviderFailureReason::ServerError];
        // Un 5xx : le bridge ne recopie que `error.message`, le type `overloaded_error` du corps est perdu.
        yield 'anthropic 529' => [static fn (): Throwable => self::convert($anthropic, 529, self::anthropicError('overloaded_error')), 529, null, ProviderFailureReason::ServerError];
        // Anthropic, en flux.
        yield 'anthropic flux 404' => [static fn (): Throwable => self::convert($anthropic, 404, self::anthropicError('not_found_error'), stream: true), 404, null, ProviderFailureReason::ModelNotFound];
        yield 'anthropic flux, surcharge' => [static fn (): Throwable => self::convertStreamEvent($anthropic, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => self::SENTINEL]]), null, null, ProviderFailureReason::ServerError];
        yield 'anthropic flux, limite de débit' => [static fn (): Throwable => self::convertStreamEvent($anthropic, ['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => self::SENTINEL]]), null, null, ProviderFailureReason::RateLimited];

        // Scaleway, hors flux.
        yield 'scaleway 403' => [static fn (): Throwable => self::convert($scaleway, 403, self::openAiError(['type' => 'permission_denied'])), null, 'permission_denied', ProviderFailureReason::PermissionDenied];
        yield 'scaleway 502' => [static fn (): Throwable => self::convert($scaleway, 502, self::openAiError(['type' => 'server_error'])), 502, null, ProviderFailureReason::ServerError];
        yield 'scaleway filtre de contenu' => [static fn (): Throwable => self::convert($scaleway, 400, self::openAiError(['code' => 'content_filter'])), null, null, ProviderFailureReason::InputRejected];
        yield 'scaleway contexte dépassé' => [static fn (): Throwable => self::convert($scaleway, 400, self::openAiError(['code' => 'context_length_exceeded'])), null, null, ProviderFailureReason::InputRejected];
        // Ni type ni code : le bridge écrit « Error "unknown" », un bouche-trou, pas un type du fournisseur.
        yield 'scaleway sans type' => [static fn (): Throwable => self::convert($scaleway, 400, self::openAiError([])), null, null, ProviderFailureReason::Unknown];
        // Scaleway, en flux (l'assistant) : aucun type lisible, le statut suffit à classer.
        yield 'scaleway flux 401' => [static fn (): Throwable => self::convert($scaleway, 401, self::openAiError(['type' => 'authentication_error']), stream: true), 401, null, ProviderFailureReason::Authentication];
        yield 'scaleway flux 403' => [static fn (): Throwable => self::convert($scaleway, 403, self::openAiError(['type' => 'permission_denied']), stream: true), 403, null, ProviderFailureReason::PermissionDenied];
        yield 'scaleway flux 429' => [static fn (): Throwable => self::convert($scaleway, 429, self::openAiError(['type' => 'rate_limit_error']), stream: true), 429, null, ProviderFailureReason::RateLimited];
        yield 'scaleway flux, erreur serveur' => [static fn (): Throwable => self::convertStreamEvent($scaleway, ['error' => ['type' => 'server_error', 'message' => self::SENTINEL]]), null, null, ProviderFailureReason::ServerError];
        yield 'scaleway flux, limite de débit' => [static fn (): Throwable => self::convertStreamEvent($scaleway, ['error' => ['type' => 'rate_limit_exceeded', 'message' => self::SENTINEL]]), null, null, ProviderFailureReason::RateLimited];

        // Arguments d'appel d'outil qui ne sont pas du JSON
        // (Symfony\AI\Platform\Exception\MalformedToolCallException) : le bridge cite le nom de
        // l'outil, qui porte ici la sentinelle. Les deux agents ont `tools: false`, le cas ne
        // devrait pas se produire ; il ne doit pas finir en `unknown`.
        yield 'anthropic flux, arguments d\'outil illisibles' => [static fn (): Throwable => self::convertStreamEvents($anthropic, self::anthropicStream(
            ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => self::SENTINEL],
            ['type' => 'input_json_delta', 'partial_json' => self::MALFORMED_JSON],
            'tool_use',
        )), null, null, ProviderFailureReason::MalformedResponse];
        yield 'scaleway, arguments d\'outil illisibles' => [static fn (): Throwable => self::convert($scaleway, 200, self::chatCompletionToolCall(self::SENTINEL, 'tool_calls')), null, null, ProviderFailureReason::MalformedResponse];
        // Le chemin réel de l'assistant : flux SSE servi par le client du bridge, converti par
        // CompletionsConversionTrait::convertStream() (ai-generic-platform).
        yield 'scaleway flux, arguments d\'outil illisibles' => [static fn (): Throwable => self::convertScalewaySse($scaleway, self::scalewayToolCallStream(self::SENTINEL, 'tool_calls')), null, null, ProviderFailureReason::MalformedResponse];

        // Sorties coupées par le plafond `max_tokens`
        // (Symfony\AI\Platform\Exception\MaxOutputTokensException) : le message du bridge est
        // fixe, la sentinelle n'est que dans le contenu coupé.
        yield 'anthropic, appel d\'outil coupé' => [static fn (): Throwable => self::convert($anthropic, 200, json_encode([
            'type' => 'message',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_test', 'name' => self::SENTINEL, 'input' => []]],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1024],
        ], \JSON_THROW_ON_ERROR)), null, null, ProviderFailureReason::OutputTruncated];
        // En flux, le bridge Anthropic lève sur tout arrêt `max_tokens`, texte compris.
        yield 'anthropic flux, texte coupé' => [static fn (): Throwable => self::convertStreamEvents($anthropic, self::anthropicStream(
            ['type' => 'text', 'text' => ''],
            ['type' => 'text_delta', 'text' => self::SENTINEL],
            'max_tokens',
        )), null, null, ProviderFailureReason::OutputTruncated];
        yield 'scaleway, appel d\'outil coupé' => [static fn (): Throwable => self::convert($scaleway, 200, self::chatCompletionToolCall(self::SENTINEL, 'length')), null, null, ProviderFailureReason::OutputTruncated];
        yield 'scaleway flux, appel d\'outil coupé' => [static fn (): Throwable => self::convertScalewaySse($scaleway, self::scalewayToolCallStream(self::SENTINEL, 'length')), null, null, ProviderFailureReason::OutputTruncated];
    }

    /**
     * @param Closure():Throwable $failure
     */
    #[DataProvider('bridgeFailures')]
    public function testDescribesTheFailureByClassStatusTypeAndReasonNeverByItsMessage(Closure $failure, ?int $status, ?string $errorType, ProviderFailureReason $reason): void
    {
        $exception = $failure();
        if (ProviderFailureReason::OutputTruncated === $reason) {
            // Message fixe du bridge : il ne recopie rien du contenu coupé, qui porte la sentinelle.
            self::assertStringNotContainsString(self::SENTINEL, $exception->getMessage(), 'Le bridge recopie désormais le contenu coupé dans son message.');
        } else {
            self::assertStringContainsString(self::SENTINEL, $exception->getMessage(), 'Le scénario doit porter la sentinelle, ou il ne prouve rien.');
        }

        $description = ProviderFailure::from($exception);

        self::assertSame($exception::class, $description->exceptionClass);
        self::assertSame($status, $description->status);
        self::assertSame($errorType, $description->errorType);
        self::assertSame($reason, $description->reason);
        self::assertStringNotContainsString(self::SENTINEL, json_encode($description->toLogContext(), \JSON_THROW_ON_ERROR));
    }

    /** Une coupure du transport ou un flux terminé sans fin annoncée : la réponse s'est interrompue. */
    public function testAnInterruptedResponseIsClassifiedAsSuch(): void
    {
        self::assertSame(ProviderFailureReason::Interrupted, ProviderFailure::from(new TransportException(self::SENTINEL))->reason);
        self::assertSame(ProviderFailureReason::Interrupted, ProviderFailure::from(new IncompleteStreamException(self::SENTINEL))->reason);
    }

    /**
     * « Réessayable » ne dit rien de la nature de l'échec : une exception qui ne
     * porte que ce marqueur, sans classe, statut ni type reconnus, reste inconnue
     * (ServerException et RateLimitExceededException le portent aussi, rangées
     * par leur propre classe).
     */
    public function testTheRetryableMarkerAloneLeavesTheFailureUnknown(): void
    {
        $exception = new class(self::SENTINEL) extends Exception implements RetryableExceptionInterface {
        };

        $description = ProviderFailure::from($exception);

        self::assertNull($description->status);
        self::assertNull($description->errorType);
        self::assertSame(ProviderFailureReason::Unknown, $description->reason);
    }

    /** Ce qui ne vient pas du fournisseur (un bogue de câblage) n'est rangé dans aucune de ses pannes. */
    public function testAnythingElseIsUnknown(): void
    {
        self::assertSame(ProviderFailureReason::Unknown, ProviderFailure::from(new TypeError(self::SENTINEL))->reason);
    }

    /** Le client HTTP lève lui-même quand le bridge lit un 4xx sans le désactiver. */
    public function testReadsTheStatusOfAnHttpClientException(): void
    {
        $response = (new MockHttpClient(new MockResponse(self::SENTINEL, ['http_code' => 403])))->request('POST', 'https://ai.test/');

        self::assertSame(403, ProviderFailure::from(new ClientException($response))->status);
    }

    /** Le type n'est lu qu'en tête : un crochet ou des guillemets dans le corps ne sont jamais capturés. */
    public function testTheErrorTypeIsOnlyReadAtTheStartOfTheMessage(): void
    {
        self::assertNull(ProviderFailure::from(new RuntimeException(self::SENTINEL.' API Error [not_found_error]'))->errorType);
        self::assertNull(ProviderFailure::from(new RuntimeException(self::SENTINEL.' Error "permission_denied": "x"'))->errorType);
        self::assertNull(ProviderFailure::from(new RuntimeException(self::SENTINEL.' Unexpected response code 403'))->status);
    }

    /** Le lieu, sans contenu : un bogue de ce côté-ci se distingue d'une panne du bridge, qui lève depuis vendor/. */
    public function testTheOriginIsTheFileNameAndLineOfTheThrowSite(): void
    {
        $exception = new TypeError(self::SENTINEL);

        self::assertSame('ProviderFailureTest.php:'.$exception->getLine(), ProviderFailure::from($exception)->origin);
    }

    public function testTheLogContextCarriesTheFiveFieldsUnderStableKeys(): void
    {
        $exception = new LogicException(self::SENTINEL);

        self::assertSame([
            'exception' => LogicException::class,
            'providerStatus' => null,
            'providerErrorType' => null,
            'providerFailure' => 'unknown',
            'origin' => 'ProviderFailureTest.php:'.$exception->getLine(),
        ], ProviderFailure::from($exception)->toLogContext());
    }

    private static function convert(ResultConverterInterface $converter, int $status, string $body, bool $stream = false): Throwable
    {
        $response = (new MockHttpClient(new MockResponse($body, ['http_code' => $status])))->request('POST', 'https://ai.test/');

        return self::thrownBy(static fn (): mixed => $converter->convert(new RawHttpResult($response), ['stream' => $stream]));
    }

    /**
     * Un événement d'erreur au milieu du flux : le flux SSE est remplacé par
     * l'événement déjà décodé, le convertisseur reste le vrai.
     *
     * @param array<string, mixed> $event
     */
    private static function convertStreamEvent(ResultConverterInterface $converter, array $event): Throwable
    {
        return self::convertStreamEvents($converter, [$event]);
    }

    /**
     * Une suite d'événements déjà décodés, servie au vrai convertisseur en flux.
     *
     * @param list<array<string, mixed>> $events
     */
    private static function convertStreamEvents(ResultConverterInterface $converter, array $events): Throwable
    {
        $response = (new MockHttpClient(new MockResponse('')))->request('POST', 'https://ai.test/');
        $httpStream = new readonly class($events) implements HttpStreamInterface {
            /** @param list<array<string, mixed>> $events */
            public function __construct(private array $events)
            {
            }

            public function stream(ResponseInterface $response): iterable
            {
                yield from $this->events;
            }
        };

        return self::thrownBy(static function () use ($converter, $response, $httpStream): void {
            $result = $converter->convert(new RawHttpResult($response, $httpStream), ['stream' => true]);
            self::assertInstanceOf(StreamResult::class, $result);
            iterator_to_array($result->getContent(), false);
        });
    }

    /**
     * Flux SSE Chat Completions servi par un MockHttpClient enveloppé, comme dans
     * le bridge, dans un EventSourceHttpClient : le vrai convertisseur Scaleway
     * le lit par son décodeur SSE par défaut ({@see \Symfony\AI\Platform\Result\Stream\SseStream}).
     *
     * @param list<array<string, mixed>> $chunks
     */
    private static function convertScalewaySse(ResultConverterInterface $converter, array $chunks): Throwable
    {
        $body = '';
        foreach ($chunks as $chunk) {
            $body .= 'data: '.json_encode($chunk, \JSON_THROW_ON_ERROR)."\n\n";
        }

        $client = new EventSourceHttpClient(new MockHttpClient(new MockResponse($body."data: [DONE]\n\n", [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ])));
        $response = $client->request('POST', 'https://ai.test/');

        return self::thrownBy(static function () use ($converter, $response): void {
            $result = $converter->convert(new RawHttpResult($response), ['stream' => true]);
            self::assertInstanceOf(StreamResult::class, $result);
            iterator_to_array($result->getContent(), false);
        });
    }

    /**
     * Un message Anthropic en flux, un seul bloc de contenu, arrêté pour la raison donnée.
     *
     * @param array<string, mixed> $contentBlock
     * @param array<string, mixed> $delta
     *
     * @return list<array<string, mixed>>
     */
    private static function anthropicStream(array $contentBlock, array $delta, string $stopReason): array
    {
        return [
            ['type' => 'message_start', 'message' => ['model' => 'claude-test']],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => $contentBlock],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => $delta],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 1024]],
            ['type' => 'message_stop'],
        ];
    }

    /**
     * Morceaux Chat Completions en flux d'un appel d'outil aux arguments
     * illisibles : l'ouverture de l'appel, puis ses arguments avec la raison de fin.
     *
     * @return list<array<string, mixed>>
     */
    private static function scalewayToolCallStream(string $toolName, string $finishReason): array
    {
        return [
            ['model' => 'mistral-test', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0,
                'id' => 'call_test',
                'type' => 'function',
                'function' => ['name' => $toolName, 'arguments' => ''],
            ]]], 'finish_reason' => null]]],
            ['model' => 'mistral-test', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0,
                'function' => ['arguments' => self::MALFORMED_JSON],
            ]]], 'finish_reason' => $finishReason]]],
        ];
    }

    private static function thrownBy(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        self::fail('Le convertisseur devait lever.');
    }

    private static function anthropicError(string $type, string $message = self::SENTINEL): string
    {
        return json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]], \JSON_THROW_ON_ERROR);
    }

    /**
     * Réponse Chat Completions (compatible OpenAI, celle de Scaleway) portant un
     * appel d'outil aux arguments illisibles, arrêtée pour la raison donnée.
     */
    private static function chatCompletionToolCall(string $toolName, string $finishReason): string
    {
        return json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'mistral-test',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call_test',
                    'type' => 'function',
                    'function' => ['name' => $toolName, 'arguments' => self::MALFORMED_JSON],
                ]]],
                'finish_reason' => $finishReason,
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $fields `type` et/ou `code`, comme les envoie une API compatible OpenAI
     */
    private static function openAiError(array $fields): string
    {
        return json_encode(['error' => [...$fields, 'message' => self::SENTINEL]], \JSON_THROW_ON_ERROR);
    }
}
