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
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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
     * Colonnes : l'échec, le statut et le type attendus, la raison attendue,
     * et si la sentinelle est dans le message du bridge (oui/non).
     *
     * Cette dernière colonne dit quand l'assertion « jamais par son message »
     * prouve quelque chose. Oui : le bridge recopie le corps ou le nom de
     * l'outil, qui porte la sentinelle, et la description ne doit pas la
     * reprendre. Non : le message du bridge est fixe (sortie coupée par le
     * plafond `max_tokens`), la sentinelle n'est que dans le contenu, et
     * l'assertion est vacante — la colonne le dit plutôt que de le taire.
     *
     * Tout cas en flux passe par le vrai chemin SSE ({@see self::convertStream()}),
     * au format de son fournisseur ({@see self::anthropicSse()},
     * {@see self::openAiSse()}).
     *
     * @return iterable<string, array{Closure():Throwable, ?int, ?string, ProviderFailureReason, bool}>
     */
    public static function bridgeFailures(): iterable
    {
        $anthropic = new AnthropicResultConverter();
        $scaleway = new ScalewayResultConverter();

        // Anthropic, hors flux (le traducteur).
        yield 'anthropic 400' => [static fn (): Throwable => self::convert($anthropic, 400, self::anthropicError('invalid_request_error')), null, null, ProviderFailureReason::InputRejected, true];
        yield 'anthropic 400, entrée trop longue' => [static fn (): Throwable => self::convert($anthropic, 400, self::anthropicError('invalid_request_error', 'prompt is too long: '.self::SENTINEL)), null, null, ProviderFailureReason::InputRejected, true];
        yield 'anthropic 401' => [static fn (): Throwable => self::convert($anthropic, 401, self::anthropicError('authentication_error')), null, null, ProviderFailureReason::Authentication, true];
        yield 'anthropic 403, droit retiré' => [static fn (): Throwable => self::convert($anthropic, 403, self::anthropicError('permission_error')), null, 'permission_error', ProviderFailureReason::PermissionDenied, true];
        yield 'anthropic 404, modèle retiré' => [static fn (): Throwable => self::convert($anthropic, 404, self::anthropicError('not_found_error')), null, 'not_found_error', ProviderFailureReason::ModelNotFound, true];
        yield 'anthropic 429' => [static fn (): Throwable => self::convert($anthropic, 429, self::anthropicError('rate_limit_error')), null, null, ProviderFailureReason::RateLimited, true];
        yield 'anthropic surcharge dans un 200' => [static fn (): Throwable => self::convert($anthropic, 200, self::anthropicError('overloaded_error')), null, 'overloaded_error', ProviderFailureReason::ServerError, true];
        // Un 5xx : le bridge ne recopie que `error.message`, le type `overloaded_error` du corps est perdu.
        yield 'anthropic 529' => [static fn (): Throwable => self::convert($anthropic, 529, self::anthropicError('overloaded_error')), 529, null, ProviderFailureReason::ServerError, true];
        // Anthropic, en flux.
        yield 'anthropic flux 404' => [static fn (): Throwable => self::convertStream($anthropic, self::anthropicError('not_found_error'), 404), 404, null, ProviderFailureReason::ModelNotFound, true];
        yield 'anthropic flux, surcharge' => [static fn (): Throwable => self::convertStream($anthropic, self::anthropicSse([['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => self::SENTINEL]]])), null, null, ProviderFailureReason::ServerError, true];
        yield 'anthropic flux, limite de débit' => [static fn (): Throwable => self::convertStream($anthropic, self::anthropicSse([['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => self::SENTINEL]]])), null, null, ProviderFailureReason::RateLimited, true];

        // Scaleway, hors flux.
        yield 'scaleway 403' => [static fn (): Throwable => self::convert($scaleway, 403, self::openAiError(['type' => 'permission_denied'])), null, 'permission_denied', ProviderFailureReason::PermissionDenied, true];
        yield 'scaleway 502' => [static fn (): Throwable => self::convert($scaleway, 502, self::openAiError(['type' => 'server_error'])), 502, null, ProviderFailureReason::ServerError, true];
        yield 'scaleway filtre de contenu' => [static fn (): Throwable => self::convert($scaleway, 400, self::openAiError(['code' => 'content_filter'])), null, null, ProviderFailureReason::InputRejected, true];
        yield 'scaleway contexte dépassé' => [static fn (): Throwable => self::convert($scaleway, 400, self::openAiError(['code' => 'context_length_exceeded'])), null, null, ProviderFailureReason::InputRejected, true];
        // Ni type ni code : le bridge écrit « Error "unknown" », un bouche-trou, pas un type du fournisseur.
        yield 'scaleway sans type' => [static fn (): Throwable => self::convert($scaleway, 400, self::openAiError([])), null, null, ProviderFailureReason::Unknown, true];
        // Scaleway, en flux (l'assistant) : aucun type lisible, le statut suffit à classer.
        yield 'scaleway flux 401' => [static fn (): Throwable => self::convertStream($scaleway, self::openAiError(['type' => 'authentication_error']), 401), 401, null, ProviderFailureReason::Authentication, true];
        yield 'scaleway flux 403' => [static fn (): Throwable => self::convertStream($scaleway, self::openAiError(['type' => 'permission_denied']), 403), 403, null, ProviderFailureReason::PermissionDenied, true];
        yield 'scaleway flux 429' => [static fn (): Throwable => self::convertStream($scaleway, self::openAiError(['type' => 'rate_limit_error']), 429), 429, null, ProviderFailureReason::RateLimited, true];
        yield 'scaleway flux, erreur serveur' => [static fn (): Throwable => self::convertStream($scaleway, self::openAiSse([['error' => ['type' => 'server_error', 'message' => self::SENTINEL]]])), null, null, ProviderFailureReason::ServerError, true];
        yield 'scaleway flux, limite de débit' => [static fn (): Throwable => self::convertStream($scaleway, self::openAiSse([['error' => ['type' => 'rate_limit_exceeded', 'message' => self::SENTINEL]]])), null, null, ProviderFailureReason::RateLimited, true];

        // Arguments d'appel d'outil qui ne sont pas du JSON
        // (Symfony\AI\Platform\Exception\MalformedToolCallException) : le bridge cite le nom de
        // l'outil, qui porte ici la sentinelle. Les deux agents ont `tools: false`, le cas ne
        // devrait pas se produire ; il ne doit pas finir en `unknown`.
        yield 'anthropic flux, arguments d\'outil illisibles' => [static fn (): Throwable => self::convertStream($anthropic, self::anthropicSse(self::anthropicStream(
            ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => self::SENTINEL],
            ['type' => 'input_json_delta', 'partial_json' => self::MALFORMED_JSON],
            'tool_use',
        ))), null, null, ProviderFailureReason::MalformedResponse, true];
        yield 'scaleway, arguments d\'outil illisibles' => [static fn (): Throwable => self::convert($scaleway, 200, self::chatCompletionToolCall(self::SENTINEL, 'tool_calls')), null, null, ProviderFailureReason::MalformedResponse, true];
        // Le chemin réel de l'assistant : flux converti par
        // Symfony\AI\Platform\Bridge\Generic\Completions\CompletionsConversionTrait::convertStream().
        yield 'scaleway flux, arguments d\'outil illisibles' => [static fn (): Throwable => self::convertStream($scaleway, self::openAiSse(self::scalewayToolCallStream(self::SENTINEL, 'tool_calls'))), null, null, ProviderFailureReason::MalformedResponse, true];

        // Sorties coupées par le plafond `max_tokens`
        // (Symfony\AI\Platform\Exception\MaxOutputTokensException) : le message du bridge est
        // fixe, la sentinelle n'est que dans le contenu coupé (dernière colonne : non).
        yield 'anthropic, appel d\'outil coupé' => [static fn (): Throwable => self::convert($anthropic, 200, json_encode([
            'type' => 'message',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_test', 'name' => self::SENTINEL, 'input' => []]],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1024],
        ], \JSON_THROW_ON_ERROR)), null, null, ProviderFailureReason::OutputTruncated, false];
        // En flux, le bridge Anthropic lève sur tout arrêt `max_tokens`, texte compris.
        yield 'anthropic flux, texte coupé' => [static fn (): Throwable => self::convertStream($anthropic, self::anthropicSse(self::anthropicStream(
            ['type' => 'text', 'text' => ''],
            ['type' => 'text_delta', 'text' => self::SENTINEL],
            'max_tokens',
        ))), null, null, ProviderFailureReason::OutputTruncated, false];
        yield 'scaleway, appel d\'outil coupé' => [static fn (): Throwable => self::convert($scaleway, 200, self::chatCompletionToolCall(self::SENTINEL, 'length')), null, null, ProviderFailureReason::OutputTruncated, false];
        yield 'scaleway flux, appel d\'outil coupé' => [static fn (): Throwable => self::convertStream($scaleway, self::openAiSse(self::scalewayToolCallStream(self::SENTINEL, 'length'))), null, null, ProviderFailureReason::OutputTruncated, false];
    }

    /**
     * @param Closure():Throwable $failure
     */
    #[DataProvider('bridgeFailures')]
    public function testDescribesTheFailureByClassStatusTypeAndReasonNeverByItsMessage(Closure $failure, ?int $status, ?string $errorType, ProviderFailureReason $reason, bool $sentinelInMessage): void
    {
        $exception = $failure();
        self::assertSame(
            $sentinelInMessage,
            str_contains($exception->getMessage(), self::SENTINEL),
            $sentinelInMessage
                ? 'Le scénario doit porter la sentinelle dans le message du bridge, ou il ne prouve rien.'
                : 'Le bridge recopie désormais le contenu dans son message : passer la dernière colonne à oui.',
        );

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

    private static function convert(ResultConverterInterface $converter, int $status, string $body): Throwable
    {
        $response = (new MockHttpClient(new MockResponse($body, ['http_code' => $status])))->request('POST', 'https://ai.test/');

        return self::thrownBy(static fn (): mixed => $converter->convert(new RawHttpResult($response), ['stream' => false]));
    }

    /**
     * Le chemin réel d'une réponse en flux, pour les deux bridges : un
     * MockHttpClient enveloppé, comme dans leurs ModelClient, dans un
     * EventSourceHttpClient, puis le vrai convertisseur, qui lit le flux par
     * son décodeur SSE par défaut ({@see \Symfony\AI\Platform\Result\Stream\SseStream}).
     * Un 200 est servi en `text/event-stream` ; un statut d'erreur, en JSON,
     * comme le fournisseur répond avant d'ouvrir le flux.
     */
    private static function convertStream(ResultConverterInterface $converter, string $body, int $status = 200): Throwable
    {
        $client = new EventSourceHttpClient(new MockHttpClient(new MockResponse($body, [
            'http_code' => $status,
            'response_headers' => ['content-type' => 200 === $status ? 'text/event-stream' : 'application/json'],
        ])));
        $response = $client->request('POST', 'https://ai.test/');

        return self::thrownBy(static function () use ($converter, $response): void {
            $result = $converter->convert(new RawHttpResult($response), ['stream' => true]);
            self::assertInstanceOf(StreamResult::class, $result);
            iterator_to_array($result->getContent(), false);
        });
    }

    /**
     * Un flux SSE au format de l'API Anthropic : chaque événement porte une
     * ligne `event:` du nom de son type, puis sa ligne `data:` ; le flux finit
     * sur `message_stop`, sans `[DONE]`, qu'Anthropic n'envoie pas (« Streaming
     * messages », documentation de l'API). Le décodeur du bridge ne lit que
     * `data`, mais le flux reste celui que le fournisseur envoie.
     *
     * @param list<array<string, mixed>> $events
     */
    private static function anthropicSse(array $events): string
    {
        $body = '';
        foreach ($events as $event) {
            self::assertIsString($event['type'] ?? null, 'Un événement Anthropic porte son `type`.');
            $body .= 'event: '.$event['type']."\n".'data: '.json_encode($event, \JSON_THROW_ON_ERROR)."\n\n";
        }

        return $body;
    }

    /**
     * Un flux SSE compatible OpenAI (Chat Completions, celui de Scaleway) :
     * une ligne `data:` par morceau, sans nom d'événement, puis `data: [DONE]`,
     * que le décodeur ignore.
     *
     * @param list<array<string, mixed>> $chunks
     */
    private static function openAiSse(array $chunks): string
    {
        $body = '';
        foreach ($chunks as $chunk) {
            $body .= 'data: '.json_encode($chunk, \JSON_THROW_ON_ERROR)."\n\n";
        }

        return $body."data: [DONE]\n\n";
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
            // L'API peut glisser des `ping` n'importe où dans le flux ; son exemple en place un ici.
            ['type' => 'ping'],
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
