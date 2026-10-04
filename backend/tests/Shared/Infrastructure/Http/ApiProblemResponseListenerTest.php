<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use App\Shared\Infrastructure\Http\ApiProblemResponseListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Issue #322 : sous `/api`, une route qui n'est pas une opération API Platform
 * (un contrôleur) ne bénéficie ni d'`exception_to_status` ni du rendu des
 * ProblemExceptionInterface. Cet écouteur rend ces exceptions avec le même
 * corps qu'API Platform, pour toutes ces routes à la fois. Qu'il ne touche
 * jamais une route API Platform est vérifié en fonctionnel
 * (BackofficeUserRoleResourceTest) : c'est l'ordre des priorités qui le
 * garantit, pas un test d'attribut.
 */
final class ApiProblemResponseListenerTest extends TestCase
{
    public function testAProblemExceptionBecomesItsTypedProblem(): void
    {
        $event = $this->event('/api/assistant/answers', new AssistantUnavailableException());

        (new ApiProblemResponseListener())($event);

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

    public function testAnInvalidConversationIsATypedUnprocessableProblem(): void
    {
        $event = $this->event('/api/assistant/answers', new InvalidConversationException('La conversation est vide.'));

        (new ApiProblemResponseListener())($event);

        $response = $event->getResponse() ?? self::fail('Aucune réponse posée.');
        self::assertSame(422, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/errors/invalid-conversation', $problem['type'] ?? null);
    }

    /** Le cas qui manquait : un autre contrôleur que celui de l'assistant. */
    public function testAnyControllerUnderTheApiIsCovered(): void
    {
        $event = $this->event('/api/account/base-access', new BaseAccessRateLimitExceededException(new \DateTimeImmutable('+1 hour')));

        (new ApiProblemResponseListener())($event);

        $response = $event->getResponse() ?? self::fail('Aucune réponse posée.');
        self::assertSame(429, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/errors/rate-limited', $problem['type'] ?? null);
    }

    /** Chemin décodé comme le routeur le voit (issue #77). */
    public function testTheEncodedFormOfThePathIsCoveredToo(): void
    {
        $event = $this->event('/%61pi/assistant/answers', new AssistantUnavailableException());

        (new ApiProblemResponseListener())($event);

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    /**
     * Sans statut, l'exception ne dit rien du client : c'est une erreur du
     * serveur, comme API Platform la traiterait.
     */
    public function testAProblemWithoutAStatusIsAServerError(): void
    {
        $event = $this->event('/api/assistant/answers', new class extends \RuntimeException implements ProblemExceptionInterface {
            public function getType(): string
            {
                return '/errors/sans-statut';
            }

            public function getTitle(): string
            {
                return 'sans-statut';
            }

            public function getStatus(): ?int
            {
                return null;
            }

            public function getDetail(): string
            {
                return 'Statut absent.';
            }

            public function getInstance(): ?string
            {
                return null;
            }
        });

        (new ApiProblemResponseListener())($event);

        $response = $event->getResponse() ?? self::fail('Aucune réponse posée.');
        self::assertSame(500, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(500, $problem['status'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOutsideTheApi(): iterable
    {
        yield 'voisin par préfixe' => ['/apix/answers'];
        yield 'hors /api' => ['/healthz'];
    }

    #[DataProvider('pathsOutsideTheApi')]
    public function testPathsOutsideTheApiAreLeftToTheUsualErrorHandling(string $path): void
    {
        $event = $this->event($path, new AssistantUnavailableException());

        (new ApiProblemResponseListener())($event);

        self::assertNull($event->getResponse());
    }

    public function testAnExceptionWithoutAProblemTypeIsLeftAlone(): void
    {
        $event = $this->event('/api/assistant/answers', new \LogicException('bogue'));

        (new ApiProblemResponseListener())($event);

        self::assertNull($event->getResponse());
    }

    /**
     * Le forward vers le contrôleur d'erreur est une sous-requête : la réponse
     * se décide sur la requête principale, une seule fois.
     */
    public function testASubRequestIsLeftAlone(): void
    {
        $event = $this->event('/api/assistant/answers', new AssistantUnavailableException(), HttpKernelInterface::SUB_REQUEST);

        (new ApiProblemResponseListener())($event);

        self::assertNull($event->getResponse());
    }

    private function event(string $path, \Throwable $exception, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            $requestType,
            $exception,
        );
    }
}
