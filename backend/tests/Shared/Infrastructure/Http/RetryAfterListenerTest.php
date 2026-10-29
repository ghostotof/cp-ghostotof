<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\RetryAfterAware;
use App\Shared\Infrastructure\Http\RetryAfterListener;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Issue #743 : l'échéance d'un blocage garde ses microsecondes, l'en-tête est
     * un nombre entier de secondes. Soustraire deux horodatages **tronqués**
     * annonçait jusqu'à une seconde trop tôt dès qu'une seconde civile s'écoulait
     * entre la fixation de l'échéance et la réponse : échéance à X+900,990,
     * réponse à X+1,001, soit 899 annoncées pour 899,989 s restantes, et le
     * client revenait alors que le limiteur bloquait encore. L'écart est arrondi
     * par excès : jamais trop tôt.
     */
    public function testTheDelayIsNeverShorterThanTheRealRemainingBlockWhenASecondElapses(): void
    {
        $clock = new MockClock('2026-10-10 12:00:00.990');
        $listener = new RetryAfterListener($clock);
        $request = Request::create('/api/login_check', 'POST');
        $response = new Response('', 429);

        // L'échéance est posée à 12:15:00.990 : 900 s exactement après l'instant courant.
        $listener->onKernelException($this->exceptionEvent($request, $this->quotaExceededAt($clock->now()->modify('+900 seconds'))));
        // Onze millisecondes plus tard, la seconde civile a changé : 12:00:01.001.
        $clock->sleep(0.011);
        $listener->onKernelResponse($this->responseEvent($request, $response));

        // Il reste 899,989 s : l'en-tête est 900, pas 899.
        self::assertSame('900', $response->headers->get('Retry-After'));
    }

    /**
     * Un reste de quelques microsecondes vaut déjà une seconde entière : 0 dirait
     * au client de revenir tout de suite, alors que le blocage court encore. Et
     * 900,000 s exactes restent 900, pas 901 : le calcul se fait en microsecondes
     * entières, pas en flottants.
     *
     * @return iterable<string, array{0: int, 1: int, 2: string}>
     */
    public static function provideRemainingBlocksAndTheirCeiling(): iterable
    {
        yield 'un reste d\'une microseconde' => [0, 1, '1'];
        yield 'une demi-seconde' => [0, 500_000, '1'];
        yield 'une seconde et un millième' => [1, 1_000, '2'];
        yield 'exactement 900 s, pas 901' => [900, 0, '900'];
        yield '900 s et une microseconde' => [900, 1, '901'];
        yield '899 s et 999999 microsecondes' => [899, 999_999, '900'];
    }

    #[DataProvider('provideRemainingBlocksAndTheirCeiling')]
    public function testTheFractionalRemainderIsRoundedUpNeverDown(int $seconds, int $microseconds, string $expected): void
    {
        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds +%d microseconds', $seconds, $microseconds));

        $response = $this->respondTo($this->quotaExceededAt($deadline));

        self::assertSame($expected, $response->headers->get('Retry-After'));
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

        $listener->onKernelException($this->exceptionEvent($request, $exception));
        $listener->onKernelResponse($this->responseEvent($request, $response));

        return $response;
    }

    private function exceptionEvent(Request $request, Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
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
        return $this->quotaExceededAt($this->clock->now()->modify($offset));
    }

    private function quotaExceededAt(DateTimeImmutable $deadline): Throwable
    {
        return new class($deadline) extends DomainException implements RetryAfterAware {
            public function __construct(public readonly DateTimeImmutable $retryAfter)
            {
                parent::__construct('quota atteint');
            }
        };
    }
}
