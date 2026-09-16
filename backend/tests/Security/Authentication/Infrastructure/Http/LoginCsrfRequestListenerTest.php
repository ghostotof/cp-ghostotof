<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Http\LoginCsrfRequestListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test unitaire isolé de la défense anti login-CSRF (issue #76) sur les deux
 * points d'entrée anonymes qui posent un cookie BEARER. Complète les tests
 * fonctionnels (AuthenticationFlowTest, BaseAccessControllerTest) sur les cas
 * limites de matching : méthode, sous-requête, chemin encodé, en-tête vide.
 */
final class LoginCsrfRequestListenerTest extends TestCase
{
    private LoginCsrfRequestListener $listener;

    protected function setUp(): void
    {
        $this->listener = new LoginCsrfRequestListener(self::createStub(SecurityAuditLoggerInterface::class));
    }

    /**
     * D5 : un rejet laisse une ligne `csrf-rejected`, un passage aucune.
     */
    #[DataProvider('guardedPaths')]
    public function testARejectionIsRecordedInTheSecurityAuditLog(string $path): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('csrfRejected');

        $this->expectException(AccessDeniedHttpException::class);

        (new LoginCsrfRequestListener($auditLogger))->__invoke($this->mainRequestEvent(Request::create($path, 'POST')));
    }

    #[DataProvider('guardedPaths')]
    public function testAnAcceptedRequestLeavesNoRecord(string $path): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('csrfRejected');

        (new LoginCsrfRequestListener($auditLogger))->__invoke($this->mainRequestEvent(
            Request::create($path, 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']),
        ));

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedPaths(): iterable
    {
        yield 'login' => ['/api/login_check'];
        yield 'base access' => ['/api/account/base-access'];
    }

    #[DataProvider('guardedPaths')]
    public function testPostWithoutTheHeaderIsRefused(string $path): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->listener->__invoke($this->mainRequestEvent(Request::create($path, 'POST')));
    }

    /**
     * Un en-tête vidé n'est pas un en-tête posé : un formulaire ne peut pas
     * l'émettre non plus, mais on refuse par cohérence avec le double-submit
     * (CsrfCookieRequestSubscriber rejette de même les chaînes vides).
     */
    #[DataProvider('guardedPaths')]
    public function testPostWithAnEmptyHeaderIsRefused(string $path): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->listener->__invoke($this->mainRequestEvent(
            Request::create($path, 'POST', server: ['HTTP_X_REQUESTED_WITH' => '']),
        ));
    }

    #[DataProvider('guardedPaths')]
    public function testPostWithTheHeaderPasses(string $path): void
    {
        $this->listener->__invoke($this->mainRequestEvent(
            Request::create($path, 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']),
        ));

        $this->addToAssertionCount(1);
    }

    /**
     * Régression issue #77 : le routeur décode `%XX`, la garde doit voir le
     * même chemin que lui — sinon `/api/login%5Fcheck` contourne la défense.
     */
    #[DataProvider('percentEncodedGuardedPaths')]
    public function testPercentEncodedPathIsGuardedLikeThePlainOne(string $encodedPath): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->listener->__invoke($this->mainRequestEvent(Request::create($encodedPath, 'POST')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function percentEncodedGuardedPaths(): iterable
    {
        yield 'login, %5F = _' => ['/api/login%5Fcheck'];
        yield 'base access, %2D = -' => ['/api/account/base%2Daccess'];
    }

    /**
     * Un formulaire HTML ne peut soumettre qu'en GET ou POST : les autres
     * méthodes n'ont pas ce vecteur, et ces routes ne les acceptent de toute
     * façon pas (405 du routeur, sans cookie posé).
     */
    #[DataProvider('guardedPaths')]
    public function testOtherMethodsAreNotGuarded(string $path): void
    {
        $this->listener->__invoke($this->mainRequestEvent(Request::create($path, 'GET')));

        $this->addToAssertionCount(1);
    }

    public function testOtherPathsAreNotGuarded(): void
    {
        // /api/contact est public et sans en-tête, mais ne pose aucun cookie :
        // un login-CSRF n'y a rien à rétrograder.
        $this->listener->__invoke($this->mainRequestEvent(Request::create('/api/contact', 'POST')));
        $this->listener->__invoke($this->mainRequestEvent(Request::create('/api/login_check/extra', 'POST')));
        $this->listener->__invoke($this->mainRequestEvent(Request::create('/api/logout', 'POST')));

        $this->addToAssertionCount(1);
    }

    public function testSubRequestsAreNotGuarded(): void
    {
        $event = new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/login_check', 'POST'),
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->listener->__invoke($event);

        $this->addToAssertionCount(1);
    }

    private function mainRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
