<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Http;

use App\Security\User\Application\BaseAccessRateLimiterInterface;
use App\Security\User\Infrastructure\Http\BaseAccessRateLimitRequestListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test unitaire du listener kernel.request qui borne le débit de
 * POST /api/account/base-access (ADR 0003 D6). Complète
 * BaseAccessControllerTest (fonctionnel) sur les cas limites de matching.
 */
final class BaseAccessRateLimitRequestListenerTest extends TestCase
{
    public function testPostOnTheEndpointConsumesQuotaKeyedByClientIp(): void
    {
        $limiter = new SpyBaseAccessRateLimiter();
        $listener = new BaseAccessRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(
            Request::create('/api/account/base-access', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']),
        ));

        self::assertSame(['203.0.113.7'], $limiter->consumedIdentifiers);
    }

    public function testOtherPathsAreNotRateLimited(): void
    {
        $limiter = new SpyBaseAccessRateLimiter();
        $listener = new BaseAccessRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(Request::create('/api/account/base-access/extra', 'POST')));
        $listener->__invoke($this->mainRequestEvent(Request::create('/api/contact', 'POST')));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    public function testOtherMethodsAreNotRateLimited(): void
    {
        $limiter = new SpyBaseAccessRateLimiter();
        $listener = new BaseAccessRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(Request::create('/api/account/base-access', 'GET')));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    /**
     * Régression issue #77 : `%2D` = '-'. Symfony route `/api/account/base%2Daccess`
     * vers le contrôleur (le routeur décode), le listener doit donc compter
     * cette requête comme n'importe quelle autre — sinon le quota D6 se
     * contourne d'un octet.
     */
    public function testPercentEncodedPathIsRateLimitedLikeThePlainOne(): void
    {
        $limiter = new SpyBaseAccessRateLimiter();
        $listener = new BaseAccessRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(
            Request::create('/api/account/base%2Daccess', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']),
        ));

        self::assertSame(['203.0.113.7'], $limiter->consumedIdentifiers);
    }

    private function mainRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}

/**
 * Espionne les appels à consume() sans toucher au cache Symfony.
 */
final class SpyBaseAccessRateLimiter implements BaseAccessRateLimiterInterface
{
    /** @var list<string> */
    public array $consumedIdentifiers = [];

    public function consume(string $clientIdentifier): void
    {
        $this->consumedIdentifiers[] = $clientIdentifier;
    }
}
