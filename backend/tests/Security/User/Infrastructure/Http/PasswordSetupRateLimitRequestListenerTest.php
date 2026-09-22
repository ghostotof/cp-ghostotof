<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Http;

use App\Security\User\Application\PasswordSetupRateLimiterInterface;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use App\Security\User\Infrastructure\Http\PasswordSetupRateLimitRequestListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test unitaire du listener kernel.request qui borne le débit du parcours
 * public de définition de mot de passe (audit C1 / décision D1). Le comptage
 * doit avoir lieu ici, avant API Platform, pour tout POST sur les deux routes
 * du parcours (`/api/account/password-setup` et `…/validate`, audit A7 / D6) —
 * et nulle part ailleurs.
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

    #[DataProvider('rateLimitedPaths')]
    public function testPostOnAPasswordSetupRouteConsumesQuotaKeyedByClientIp(string $path): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(
            Request::create($path, 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']),
        ));

        self::assertSame(['203.0.113.7'], $limiter->consumedIdentifiers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rateLimitedPaths(): iterable
    {
        yield 'définition du mot de passe' => ['/api/account/password-setup'];
        yield 'validation du jeton' => ['/api/account/password-setup/validate'];
    }

    /**
     * POST seul : les deux routes n'exposent rien d'autre, tout le reste est
     * un 405 du routeur (priorité 32, avant ce listener) qui ne coûte rien.
     */
    #[DataProvider('otherHttpMethods')]
    public function testOtherHttpMethodsAreNotRateLimited(string $method): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(Request::create('/api/account/password-setup', $method)));
        $listener->__invoke($this->mainRequestEvent(Request::create('/api/account/password-setup/validate', $method)));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherHttpMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
    }

    /**
     * Le quota couvre deux chemins EXACTS : ni une route sœur qui partagerait
     * le préfixe, ni un chemin que le routeur ne sert pas (il répond 404 avant
     * ce listener — consommer le quota d'un 404 n'aurait aucun sens).
     */
    #[DataProvider('pathsNextToTheRoutes')]
    public function testPathsNextToTheRoutesAreNotRateLimited(string $path): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(Request::create($path, 'POST')));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsNextToTheRoutes(): iterable
    {
        yield 'route sœur au même préfixe' => ['/api/account/password-setup-autre'];
        yield 'ancien contrat, jeton dans le chemin' => ['/api/account/password-setup/deadbeef'];
        yield 'barre oblique finale' => ['/api/account/password-setup/'];
    }

    public function testSubRequestIsIgnored(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke(new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/account/password-setup', 'POST'),
            HttpKernelInterface::SUB_REQUEST,
        ));

        self::assertSame([], $limiter->consumedIdentifiers);
    }

    public function testRequestWithoutClientIpFallsBackToASharedCounterKey(): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $request = Request::create('/api/account/password-setup/validate', 'POST');
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
                Request::create('/api/account/password-setup', 'POST'),
            ));
            self::fail('PasswordSetupRateLimitExceededException attendue.');
        } catch (PasswordSetupRateLimitExceededException $exception) {
            self::assertSame($retryAfter, $exception->retryAfter);
        }
    }

    /**
     * Régression issue #77 : `%2D` = '-'. API Platform sert
     * `/api/account/password%2Dsetup` comme le chemin en clair (le routeur
     * décode), le quota doit donc être consommé à l'identique.
     */
    #[DataProvider('percentEncodedPaths')]
    public function testPercentEncodedPathIsRateLimitedLikeThePlainOne(string $path): void
    {
        $limiter = new SpyPasswordSetupRateLimiter();
        $listener = new PasswordSetupRateLimitRequestListener($limiter);

        $listener->__invoke($this->mainRequestEvent(
            Request::create($path, 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']),
        ));

        self::assertSame(['203.0.113.7'], $limiter->consumedIdentifiers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function percentEncodedPaths(): iterable
    {
        yield 'route racine' => ['/api/account/password%2Dsetup'];
        yield 'validation' => ['/api/account/password%2Dsetup/valid%61te'];
        yield 'barre oblique encodée' => ['/api/account/password-setup%2Fvalidate'];
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
