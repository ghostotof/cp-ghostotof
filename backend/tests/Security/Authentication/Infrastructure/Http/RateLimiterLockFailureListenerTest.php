<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Http\RateLimiterLockFailureListener;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;

/**
 * Test unitaire de la portée du listener (issue #276). Le rendu de bout en
 * bout, une route par lieu de consommation d'un limiteur, est couvert par
 * RateLimiterLockFailureTest.
 */
final class RateLimiterLockFailureListenerTest extends TestCase
{
    /** Nom de verrou réaliste : la clé du limiteur y figure, ici une IP. */
    private const string LOCK_RESOURCE = 'contact_form-203.0.113.7';

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
    }

    public function testALockFailureUnderTheApiBecomesA503ProblemAndIsAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('rateLimiterUnavailable');
        $event = $this->event(Request::create('/api/contact', 'POST'), $this->acquiringFailure());

        $this->listener($auditLogger)->__invoke($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame((string) RateLimiterLockFailureListener::RETRY_AFTER_SECONDS, $response->headers->get('Retry-After'));
    }

    /**
     * L'incident reste visible côté exploitation — le listener répond, donc
     * le noyau ne journalise plus rien — mais sans le message de l'exception,
     * qui nomme la ressource verrouillée (clé du limiteur : IP, identifiant).
     */
    public function testTheIncidentIsLoggedAsAnErrorWithoutTheLockResource(): void
    {
        $event = $this->event(Request::create('/api/contact', 'POST'), new \LogicException('wrapper', 0, $this->acquiringFailure()));

        $this->listener()->__invoke($event);

        $records = $this->logHandler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame([\LogicException::class, LockAcquiringException::class], $records[0]->context['exceptionClasses']);
        self::assertSame('/api/contact', $records[0]->context['path']);
        self::assertStringNotContainsString(
            self::LOCK_RESOURCE,
            json_encode([$records[0]->message, $records[0]->context], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Prise, libération (connexion perdue après la prise) et conflit relayé
     * par le store en mémoire interne au composant : trois façons pour le
     * verrou de céder, une seule réponse. Une exception qui enveloppe la panne
     * reste une panne du verrou.
     */
    #[DataProvider('lockFailures')]
    public function testEveryWayTheLockCanFailIsRecognised(\Throwable $failure): void
    {
        $event = $this->event(Request::create('/api/login_check', 'POST'), $failure);

        $this->listener()->__invoke($event);

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function lockFailures(): iterable
    {
        yield 'prise' => [new LockAcquiringException('Failed to acquire the "'.self::LOCK_RESOURCE.'" lock.')];
        yield 'libération' => [new LockReleasingException('Failed to release the "'.self::LOCK_RESOURCE.'" lock.')];
        yield 'conflit' => [new LockConflictedException()];
        yield 'enveloppée' => [new \LogicException('wrapper', 0, new LockReleasingException('Failed to release.'))];
    }

    /**
     * Régression issue #77 : le routeur sert `/%61pi/contact` comme
     * `/api/contact`, la décision doit se prendre sur le chemin décodé.
     */
    public function testAPercentEncodedApiPathIsCovered(): void
    {
        $event = $this->event(Request::create('/%61pi/contact', 'POST'), $this->acquiringFailure());

        $this->listener()->__invoke($event);

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    /**
     * Hors périmètre : ni réponse, ni événement d'audit, ni ligne de journal.
     * Un audit émis à tort fausserait le filtre `rate-limiter-unavailable`.
     */
    #[DataProvider('outOfScopeEvents')]
    public function testOutOfScopeEventsAreLeftAlone(string $path, \Throwable $throwable, int $requestType): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('rateLimiterUnavailable');
        $event = $this->event(Request::create($path, 'POST'), $throwable, $requestType);

        $this->listener($auditLogger)->__invoke($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $this->logHandler->getRecords());
    }

    /**
     * @return iterable<string, array{string, \Throwable, int}>
     */
    public static function outOfScopeEvents(): iterable
    {
        $lockFailure = new LockAcquiringException('Failed to acquire the "'.self::LOCK_RESOURCE.'" lock.');

        yield 'autre exception' => ['/api/contact', new \RuntimeException('autre panne'), HttpKernelInterface::MAIN_REQUEST];
        yield 'voisin de /api' => ['/apix/contact', $lockFailure, HttpKernelInterface::MAIN_REQUEST];
        yield 'sous-requête' => ['/api/contact', $lockFailure, HttpKernelInterface::SUB_REQUEST];
    }

    private function listener(?SecurityAuditLoggerInterface $auditLogger = null): RateLimiterLockFailureListener
    {
        return new RateLimiterLockFailureListener(
            $auditLogger ?? self::createStub(SecurityAuditLoggerInterface::class),
            new Logger('test', [$this->logHandler]),
        );
    }

    private function acquiringFailure(): LockAcquiringException
    {
        return new LockAcquiringException('Failed to acquire the "'.self::LOCK_RESOURCE.'" lock.');
    }

    private function event(Request $request, \Throwable $throwable, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent(self::createStub(HttpKernelInterface::class), $request, $requestType, $throwable);
    }
}
