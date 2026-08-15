<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test unitaire isolé de la protection CSRF double-submit-cookie, en
 * complément de sa vérification indirecte par AuthenticationFlowTest (test
 * fonctionnel bout-en-bout). Couvre spécifiquement les cas limites relevés
 * par la revue de code : l'exclusion de /api/contact, et le comportement de
 * hash_equals() sur deux chaînes vides.
 */
final class CsrfCookieRequestSubscriberTest extends TestCase
{
    private CsrfCookieRequestSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new CsrfCookieRequestSubscriber();
    }

    public function testSafeMethodIsNeverChecked(): void
    {
        $request = Request::create('/api/backoffice/stats', 'GET');

        $this->subscriber->__invoke($this->mainRequestEvent($request));

        $this->addToAssertionCount(1);
    }

    public function testUnsafeMethodOnLoginCheckIsExcluded(): void
    {
        $request = Request::create('/api/login_check', 'POST');

        $this->subscriber->__invoke($this->mainRequestEvent($request));

        $this->addToAssertionCount(1);
    }

    public function testUnsafeMethodOnContactIsExcluded(): void
    {
        $request = Request::create('/api/contact', 'POST');

        $this->subscriber->__invoke($this->mainRequestEvent($request));

        $this->addToAssertionCount(1);
    }

    public function testUnsafeMethodOutsideApiIsNeverChecked(): void
    {
        $request = Request::create('/login', 'POST');

        $this->subscriber->__invoke($this->mainRequestEvent($request));

        $this->addToAssertionCount(1);
    }

    public function testSubRequestIsNeverChecked(): void
    {
        $request = Request::create('/api/backoffice/stats', 'POST');

        // Aucun cookie/header CSRF fourni : lèverait normalement une
        // AccessDeniedHttpException si c'était la requête principale.
        $this->subscriber->__invoke(new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        ));

        $this->addToAssertionCount(1);
    }

    public function testUnsafeMethodOnProtectedPathWithoutCookieIsRejected(): void
    {
        $request = Request::create('/api/backoffice/stats', 'POST', server: ['HTTP_X_XSRF_TOKEN' => 'token-value']);

        $this->expectException(AccessDeniedHttpException::class);

        $this->subscriber->__invoke($this->mainRequestEvent($request));
    }

    public function testUnsafeMethodOnProtectedPathWithoutHeaderIsRejected(): void
    {
        $request = Request::create('/api/backoffice/stats', 'POST', cookies: ['XSRF-TOKEN' => 'token-value']);

        $this->expectException(AccessDeniedHttpException::class);

        $this->subscriber->__invoke($this->mainRequestEvent($request));
    }

    public function testUnsafeMethodOnProtectedPathWithMismatchedTokensIsRejected(): void
    {
        $request = Request::create('/api/backoffice/stats', 'POST', server: ['HTTP_X_XSRF_TOKEN' => 'attacker-value'], cookies: ['XSRF-TOKEN' => 'legitimate-value']);

        $this->expectException(AccessDeniedHttpException::class);

        $this->subscriber->__invoke($this->mainRequestEvent($request));
    }

    public function testUnsafeMethodOnProtectedPathWithMatchingTokensIsAccepted(): void
    {
        $request = Request::create('/api/backoffice/stats', 'POST', server: ['HTTP_X_XSRF_TOKEN' => 'same-value'], cookies: ['XSRF-TOKEN' => 'same-value']);

        $this->subscriber->__invoke($this->mainRequestEvent($request));

        $this->addToAssertionCount(1);
    }

    /**
     * Régression : un cookie XSRF-TOKEN vidé (ex. "XSRF-TOKEN=;", posé par un
     * clearCookie() côté client ou un état de session incohérent) est lu
     * comme une chaîne vide par Symfony, pas comme absent (`null`). Sans
     * garde explicite sur la chaîne vide, hash_equals('', '') vaut `true`
     * et un header également vide contournerait la protection.
     */
    public function testEmptyCookieAndHeaderAreRejectedNotTreatedAsMatching(): void
    {
        $request = Request::create('/api/backoffice/stats', 'POST', server: ['HTTP_X_XSRF_TOKEN' => ''], cookies: ['XSRF-TOKEN' => '']);

        $this->expectException(AccessDeniedHttpException::class);

        $this->subscriber->__invoke($this->mainRequestEvent($request));
    }

    private function mainRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
