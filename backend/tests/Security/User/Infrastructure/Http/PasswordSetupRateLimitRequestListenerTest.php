<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Http;

use App\Security\User\Application\PasswordSetupRateLimiterInterface;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use App\Security\User\Infrastructure\Http\PasswordSetupRateLimitRequestListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test unitaire du listener kernel.request qui borne le débit du parcours
 * public de définition de mot de passe (audit C1 / décision D1). Le comptage
 * doit avoir lieu ici, avant API Platform, pour tout GET/POST sur le préfixe
 * `/api/account/password-setup/` — et nulle part ailleurs.
 */
final class PasswordSetupRateLimitRequestListenerTest extends TestCase
{
    public function testRequestOutsideThePrefixIsNotRateLimited(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(Request::create('/api/contact', 'POST')));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    public function testGetOnThePrefixConsumesQuotaKeyedByClientIp(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(
            Request::create('/api/account/password-setup/deadbeef', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']),
        ));

        self::assertSame(['203.0.113.7'], $limiter->consumedIdentifiers);
    }

    public function testPostOnThePrefixConsumesTheSameQuota(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(
            Request::create('/api/account/password-setup/deadbeef', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']),
        ));

        self::assertSame(['203.0.113.7'], $limiter->consumedIdentifiers);
    }

    public function testOtherHttpMethodsOnThePrefixAreNotRateLimited(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(Request::create('/api/account/password-setup/deadbeef', 'DELETE')));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    public function testSubRequestIsIgnored(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke(new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/account/password-setup/deadbeef', 'POST'),
            HttpKernelInterface::SUB_REQUEST,
        ));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    public function testRequestWithoutClientIpFallsBackToASharedCounterKey(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $request = Request::create('/api/account/password-setup/deadbeef', 'GET');
        $request->server->remove('REMOTE_ADDR');

        $listener->__invoke($this->mainRequestEvent($request));

        self::assertSame(['unknown'], $limiter->consumedIdentifiers);
    }

    public function testExceededQuotaPropagatesTheDomainException(): void
    {
        $retryAfter = new \DateTimeImmutable('+30 minutes');
        $limiter = new SpyPasswordSetupRateLimiter(throwOn: 1, retryAfter: $retryAfter);
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        try {
            $listener->__invoke($this->mainRequestEvent(
                Request::create('/api/account/password-setup/deadbeef', 'POST'),
            ));
            self::fail('PasswordSetupRateLimitExceededException attendue.');
        } catch (PasswordSetupRateLimitExceededException $exception) {
            self::assertSame($retryAfter, $exception->retryAfter);
        }
    }

    private function mainRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}

/**
 * Espionne les appels à consume() sans toucher au cache Symfony ; peut être
 * réglé pour lever l'exception de dépassement de quota au n-ième appel.
 */
final class SpyPasswordSetupRateLimiter implements PasswordSetupRateLimiterInterface
{
    /** @var list<string> */
    public array $consumedIdentifiers = [];

    private int $calls = 0;

    public function __construct(
        private readonly ?int $throwOn = null,
        private readonly ?\DateTimeImmutable $retryAfter = null,
    ) {
    }

    public function consume(string $clientIdentifier): void
    {
        ++$this->calls;
        $this->consumedIdentifiers[] = $clientIdentifier;

        if (null !== $this->throwOn && $this->calls >= $this->throwOn) {
            throw new PasswordSetupRateLimitExceededException($this->retryAfter ?? new \DateTimeImmutable('+1 hour'));
        }
    }
}
