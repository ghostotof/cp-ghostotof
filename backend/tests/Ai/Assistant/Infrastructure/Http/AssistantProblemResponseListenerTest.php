<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Http;

use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Infrastructure\Http\AssistantProblemResponseListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * La route de l'assistant n'est pas une opération API Platform : sans ce
 * listener, `exception_to_status` et ProblemExceptionInterface ne s'appliquent
 * pas, et un fournisseur indisponible sortait en 500 générique.
 */
final class AssistantProblemResponseListenerTest extends TestCase
{
    public function testAProblemExceptionUnderTheAssistantPathBecomesItsTypedProblem(): void
    {
        $event = $this->event('/api/assistant/answers', new AssistantUnavailableException());

        (new AssistantProblemResponseListener())($event);

        $response = $event->getResponse() ?? self::fail('Aucune réponse posée.');
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'type' => '/errors/assistant-unavailable',
            'title' => 'assistant-unavailable',
            'status' => 503,
            'detail' => "L'assistant est indisponible. Réessayez plus tard.",
        ], $problem);
    }

    /** Chemin décodé comme le routeur le voit (issue #77). */
    public function testTheEncodedFormOfThePathIsCoveredToo(): void
    {
        $event = $this->event('/api/%61ssistant/answers', new AssistantUnavailableException());

        (new AssistantProblemResponseListener())($event);

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOutsideTheAssistant(): iterable
    {
        yield 'une autre route /api' => ['/api/backoffice/translations'];
        yield 'un voisin par préfixe' => ['/api/assistants'];
        yield 'hors /api' => ['/healthz'];
    }

    #[DataProvider('pathsOutsideTheAssistant')]
    public function testOtherPathsAreLeftToTheUsualErrorHandling(string $path): void
    {
        $event = $this->event($path, new AssistantUnavailableException());

        (new AssistantProblemResponseListener())($event);

        self::assertNull($event->getResponse());
    }

    public function testAnExceptionWithoutAProblemTypeIsLeftAlone(): void
    {
        $event = $this->event('/api/assistant/answers', new \LogicException('bogue'));

        (new AssistantProblemResponseListener())($event);

        self::assertNull($event->getResponse());
    }

    private function event(string $path, \Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
