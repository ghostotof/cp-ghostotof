<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\RetryAfterAware;
use App\Shared\Infrastructure\Http\RetryAfterListener;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * Test unitaire de l'écouteur commun `Retry-After` (issue #273). L'horloge
 * figée rend la valeur exacte vérifiable, ce que `time()` en dur interdisait.
 * Le câblage réel (priorité face aux écouteurs qui posent la réponse) est
 * couvert par les tests fonctionnels des cinq routes limitées.
 */
final class RetryAfterListenerTest extends TestCase
{
    private const string NOW = '2026-10-02 12:00:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
    }

    public function testTheHeaderCarriesTheExactNumberOfSecondsUntilTheDeadline(): void
    {
        $response = $this->respondTo($this->quotaExceeded('+42 seconds'));

        self::assertSame('42', $response->headers->get('Retry-After'));
    }

    /**
     * Une échéance déjà passée (requête lente, horloge qui a tourné entre la
     * levée et la réponse) ne doit jamais produire un délai négatif.
     */
    public function testADeadlineAlreadyPastGivesZeroRatherThanANegativeDelay(): void
    {
        $response = $this->respondTo($this->quotaExceeded('-5 seconds'));

        self::assertSame('0', $response->headers->get('Retry-After'));
    }

    /**
     * La vraie borne : l'échéance tombe à la seconde même de la réponse.
     */
    public function testADeadlineExactlyNowGivesZero(): void
    {
        $response = $this->respondTo($this->quotaExceeded('+0 seconds'));

        self::assertSame('0', $response->headers->get('Retry-After'));
    }

    /**
     * Si le rendu du 429 échoue à son tour, le noyau repasse par
     * `kernel.exception` et sort un 500 — l'échéance reste pourtant notée sur
     * la requête. Un `Retry-After` sur une erreur serveur dirait au client
     * d'attendre un quota qu'on ne lui a pas annoncé.
     */
    public function testAResponseThatIsNotA429GetsNoHeaderEvenWithADeadlineNoted(): void
    {
        $response = $this->respondTo($this->quotaExceeded('+42 seconds'), 500);

        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testAnExceptionWithoutDeadlineLeavesTheResponseAlone(): void
    {
        $response = $this->respondTo(new DomainException('autre chose'));

        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testAResponseWithoutExceptionLeavesTheResponseAlone(): void
    {
        $listener = new RetryAfterListener($this->clock);
        $response = new Response();

        $listener->onKernelResponse($this->responseEvent(Request::create('/api/contact'), $response));

        self::assertFalse($response->headers->has('Retry-After'));
    }

    private function respondTo(Throwable $exception, int $status = 429): Response
    {
        $listener = new RetryAfterListener($this->clock);
        $request = Request::create('/api/contact', 'POST');
        $response = new Response('', $status);

        $listener->onKernelException(new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        ));
        $listener->onKernelResponse($this->responseEvent($request, $response));

        return $response;
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }

    private function quotaExceeded(string $offset): Throwable
    {
        // Dérivée de l'horloge elle-même : MockClock est en UTC, un
        // `new DateTimeImmutable()` suivrait le fuseau par défaut de PHP.
        $deadline = $this->clock->now()->modify($offset);

        return new class($deadline) extends DomainException implements RetryAfterAware {
            public function __construct(public readonly DateTimeImmutable $retryAfter)
            {
                parent::__construct('quota atteint');
            }
        };
    }
}
