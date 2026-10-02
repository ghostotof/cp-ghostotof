<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Http\RateLimiterLockFailureListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;

/**
 * Test unitaire de la portée du listener (issue #276). Le rendu de bout en
 * bout, login et route API Platform, est couvert par RateLimiterLockFailureTest.
 */
final class RateLimiterLockFailureListenerTest extends TestCase
{
    public function testALockFailureUnderTheApiBecomesA503ProblemAndIsAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('rateLimiterUnavailable');
        $event = $this->event(Request::create('/api/contact', 'POST'), $this->lockFailure());

        (new RateLimiterLockFailureListener($auditLogger))->__invoke($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame((string) RateLimiterLockFailureListener::RETRY_AFTER_SECONDS, $response->headers->get('Retry-After'));
    }

    /**
     * Une exception qui enveloppe la panne du verrou (un futur appelant qui
     * la rattrape pour la relancer en contexte) reste une panne du verrou.
     */
    public function testALockFailureWrappedInAnotherExceptionIsRecognised(): void
    {
        $event = $this->event(Request::create('/api/login_check', 'POST'), new \LogicException('wrapper', 0, $this->lockFailure()));

        (new RateLimiterLockFailureListener(self::createStub(SecurityAuditLoggerInterface::class)))->__invoke($event);

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    /**
     * Régression issue #77 : le routeur sert `/%61pi/contact` comme
     * `/api/contact`, la décision doit se prendre sur le chemin décodé.
     */
    public function testAPercentEncodedApiPathIsCovered(): void
    {
        $event = $this->event(Request::create('/%61pi/contact', 'POST'), $this->lockFailure());

        (new RateLimiterLockFailureListener(self::createStub(SecurityAuditLoggerInterface::class)))->__invoke($event);

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    public function testAnotherExceptionIsLeftAlone(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('rateLimiterUnavailable');
        $event = $this->event(Request::create('/api/contact', 'POST'), new \RuntimeException('autre panne'));

        (new RateLimiterLockFailureListener($auditLogger))->__invoke($event);

        self::assertNull($event->getResponse());
    }

    public function testAPathOutsideTheApiIsLeftAlone(): void
    {
        $event = $this->event(Request::create('/apix/contact', 'POST'), $this->lockFailure());

        (new RateLimiterLockFailureListener(self::createStub(SecurityAuditLoggerInterface::class)))->__invoke($event);

        self::assertNull($event->getResponse());
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $event = $this->event(Request::create('/api/contact', 'POST'), $this->lockFailure(), HttpKernelInterface::SUB_REQUEST);

        (new RateLimiterLockFailureListener(self::createStub(SecurityAuditLoggerInterface::class)))->__invoke($event);

        self::assertNull($event->getResponse());
    }

    private function lockFailure(): LockAcquiringException
    {
        return new LockAcquiringException('Failed to acquire the "contact_form-203.0.113.7" lock.');
    }

    private function event(Request $request, \Throwable $throwable, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent(self::createStub(HttpKernelInterface::class), $request, $requestType, $throwable);
    }
}
