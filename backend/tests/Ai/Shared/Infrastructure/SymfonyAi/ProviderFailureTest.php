<?php

declare(strict_types=1);

namespace App\Tests\Ai\Shared\Infrastructure\SymfonyAi;

use App\Ai\Shared\Infrastructure\SymfonyAi\ProviderFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\ResultConverter as AnthropicResultConverter;
use Symfony\AI\Platform\Bridge\Scaleway\Llm\ResultConverter as ScalewayResultConverter;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Les exceptions viennent des vrais ResultConverter des deux bridges
 * (symfony/ai-*-platform 0.13.0), nourris de réponses simulées : c'est le
 * format réellement levé qui est lu, et une montée de version qui le change
 * fait rougir ce test plutôt que de rendre le journal muet. Aucun appel ne sort.
 *
 * Chaque corps porte une sentinelle : la description ne doit jamais la contenir.
 */
final class ProviderFailureTest extends TestCase
{
    private const string SENTINEL = 'SENTINELLE-FOURNISSEUR';

    /**
     * @return iterable<string, array{\Closure(): \Throwable, ?int, ?string}>
     */
    public static function bridgeFailures(): iterable
    {
        $anthropic = new AnthropicResultConverter();
        $scaleway = new ScalewayResultConverter();

        // Anthropic, hors flux (le traducteur).
        yield 'anthropic 400' => [static fn (): \Throwable => self::convert($anthropic, 400, self::anthropicError('invalid_request_error')), null, null];
        yield 'anthropic 404, modèle retiré' => [static fn (): \Throwable => self::convert($anthropic, 404, self::anthropicError('not_found_error')), null, 'not_found_error'];
        yield 'anthropic 403, droit retiré' => [static fn (): \Throwable => self::convert($anthropic, 403, self::anthropicError('permission_error')), null, 'permission_error'];
        yield 'anthropic surcharge dans un 200' => [static fn (): \Throwable => self::convert($anthropic, 200, self::anthropicError('overloaded_error')), null, 'overloaded_error'];
        yield 'anthropic 529' => [static fn (): \Throwable => self::convert($anthropic, 529, self::anthropicError('overloaded_error')), 529, null];
        yield 'anthropic 429' => [static fn (): \Throwable => self::convert($anthropic, 429, self::anthropicError('rate_limit_error')), null, null];
        // Anthropic, en flux.
        yield 'anthropic flux 404' => [static fn (): \Throwable => self::convert($anthropic, 404, self::anthropicError('not_found_error'), stream: true), 404, null];
        yield 'anthropic flux, événement error' => [static fn (): \Throwable => self::convertStreamEvent($anthropic, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => self::SENTINEL]]), null, null];

        // Scaleway, hors flux.
        yield 'scaleway 403' => [static fn (): \Throwable => self::convert($scaleway, 403, self::openAiError('permission_denied')), null, 'permission_denied'];
        yield 'scaleway 502' => [static fn (): \Throwable => self::convert($scaleway, 502, self::openAiError('server_error')), 502, null];
        // Scaleway, en flux (l'assistant).
        yield 'scaleway flux 403' => [static fn (): \Throwable => self::convert($scaleway, 403, self::openAiError('permission_denied'), stream: true), 403, null];
        yield 'scaleway flux, événement error' => [static fn (): \Throwable => self::convertStreamEvent($scaleway, ['error' => ['type' => 'server_error', 'message' => self::SENTINEL]]), null, null];
    }

    /**
     * @param \Closure(): \Throwable $failure
     */
    #[DataProvider('bridgeFailures')]
    public function testDescribesTheFailureByClassStatusAndTypeNeverByItsMessage(\Closure $failure, ?int $status, ?string $errorType): void
    {
        $exception = $failure();
        self::assertStringContainsString(self::SENTINEL, $exception->getMessage(), 'Le scénario doit porter la sentinelle, ou il ne prouve rien.');

        $description = ProviderFailure::from($exception);

        self::assertSame($exception::class, $description->exceptionClass);
        self::assertSame($status, $description->status);
        self::assertSame($errorType, $description->errorType);
        self::assertStringNotContainsString(self::SENTINEL, json_encode($description->toLogContext(), \JSON_THROW_ON_ERROR));
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
        self::assertNull(ProviderFailure::from(new \RuntimeException(self::SENTINEL.' API Error [not_found_error]'))->errorType);
        self::assertNull(ProviderFailure::from(new \RuntimeException(self::SENTINEL.' Error "unknown": "x"'))->errorType);
        self::assertNull(ProviderFailure::from(new \RuntimeException(self::SENTINEL.' Unexpected response code 403'))->status);
    }

    /** Le lieu, sans contenu : un bogue de ce côté-ci se distingue d'une panne du bridge, qui lève depuis vendor/. */
    public function testTheOriginIsTheFileNameAndLineOfTheThrowSite(): void
    {
        $exception = new \TypeError(self::SENTINEL);

        self::assertSame('ProviderFailureTest.php:'.$exception->getLine(), ProviderFailure::from($exception)->origin);
    }

    public function testTheLogContextCarriesTheFourFieldsUnderStableKeys(): void
    {
        $exception = new \LogicException(self::SENTINEL);

        self::assertSame([
            'exception' => \LogicException::class,
            'providerStatus' => null,
            'providerErrorType' => null,
            'origin' => 'ProviderFailureTest.php:'.$exception->getLine(),
        ], ProviderFailure::from($exception)->toLogContext());
    }

    private static function convert(ResultConverterInterface $converter, int $status, string $body, bool $stream = false): \Throwable
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
    private static function convertStreamEvent(ResultConverterInterface $converter, array $event): \Throwable
    {
        $response = (new MockHttpClient(new MockResponse('')))->request('POST', 'https://ai.test/');
        $httpStream = new readonly class($event) implements HttpStreamInterface {
            /** @param array<string, mixed> $event */
            public function __construct(private array $event)
            {
            }

            public function stream(ResponseInterface $response): iterable
            {
                yield $this->event;
            }
        };

        return self::thrownBy(static function () use ($converter, $response, $httpStream): void {
            $result = $converter->convert(new RawHttpResult($response, $httpStream), ['stream' => true]);
            self::assertInstanceOf(StreamResult::class, $result);
            iterator_to_array($result->getContent(), false);
        });
    }

    private static function thrownBy(\Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $exception) {
            return $exception;
        }

        self::fail('Le convertisseur devait lever.');
    }

    private static function anthropicError(string $type): string
    {
        return json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => self::SENTINEL]], \JSON_THROW_ON_ERROR);
    }

    private static function openAiError(string $type): string
    {
        return json_encode(['error' => ['type' => $type, 'message' => self::SENTINEL]], \JSON_THROW_ON_ERROR);
    }
}
