<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Log\SecurityEventsSubscriber;
use App\Security\User\Domain\Entity\CpgUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Le subscriber ne fait que traduire les événements Symfony en appels au
 * journal d'audit (D5) : c'est le *tri* qui est testé ici — quel événement,
 * sur quel firewall, avec quel identifiant — pas le contenu des lignes
 * (SecurityAuditLoggerTest).
 */
final class SecurityEventsSubscriberTest extends TestCase
{
    private (SecurityAuditLoggerInterface&MockObject)|null $auditLogger = null;

    /**
     * Créé à la demande : PHPUnit 13 signale un mock sans attente, et le
     * test des événements souscrits n'en a pas besoin.
     */
    private function auditLogger(): SecurityAuditLoggerInterface&MockObject
    {
        return $this->auditLogger ??= $this->createMock(SecurityAuditLoggerInterface::class);
    }

    private function subscriber(): SecurityEventsSubscriber
    {
        return new SecurityEventsSubscriber($this->auditLogger());
    }

    public function testASuccessfulLoginOnTheLoginFirewallIsLogged(): void
    {
        $this->auditLogger()->expects(self::once())->method('loginSucceeded')->with('jane');

        $this->subscriber()->onLoginSuccess($this->loginSuccess('login', 'jane'));
    }

    /**
     * Le firewall `api` ré-authentifie le JWT à CHAQUE requête et dispatche
     * lui aussi LoginSuccessEvent : sans ce filtre, chaque appel authentifié
     * produirait une ligne « login réussi », ce qui noierait les vrais logins
     * — et ferait mentir le journal.
     */
    public function testAJwtAuthenticationOnTheApiFirewallIsNotALogin(): void
    {
        $this->auditLogger()->expects(self::never())->method('loginSucceeded');

        $this->subscriber()->onLoginSuccess($this->loginSuccess('api', 'jane'));
    }

    public function testAFailedLoginIsLoggedWithTheAttemptedIdentifier(): void
    {
        $this->auditLogger()->expects(self::once())->method('loginFailed')->with('jane');
        $this->auditLogger()->expects(self::never())->method('loginThrottled');

        $this->subscriber()->onLoginFailure($this->loginFailure('login', new BadCredentialsException(), 'jane'));
    }

    public function testAThrottledLoginIsItsOwnEvent(): void
    {
        $this->auditLogger()->expects(self::once())->method('loginThrottled')->with('jane');
        $this->auditLogger()->expects(self::never())->method('loginFailed');

        $this->subscriber()->onLoginFailure($this->loginFailure('login', new TooManyLoginAttemptsAuthenticationException(), 'jane'));
    }

    public function testAFailureWithoutPassportIsLoggedWithoutIdentifier(): void
    {
        $this->auditLogger()->expects(self::once())->method('loginFailed')->with(null);

        $this->subscriber()->onLoginFailure($this->loginFailure('login', new BadCredentialsException(), null));
    }

    public function testARejectedJwtOnTheApiFirewallIsNotALoginFailure(): void
    {
        $this->auditLogger()->expects(self::never())->method('loginFailed');
        $this->auditLogger()->expects(self::never())->method('loginThrottled');

        $this->subscriber()->onLoginFailure($this->loginFailure('api', new BadCredentialsException(), 'jane'));
    }

    public function testALogoutIsLoggedWithTheDepartingUser(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $this->auditLogger()->expects(self::once())->method('loggedOut')->with('jane');

        $this->subscriber()->onLogout(new LogoutEvent(Request::create('/api/logout', 'POST'), new UsernamePasswordToken($user, 'api', $user->getRoles())));
    }

    public function testALogoutWithoutTokenIsLoggedAnonymously(): void
    {
        $this->auditLogger()->expects(self::once())->method('loggedOut')->with(null);

        $this->subscriber()->onLogout(new LogoutEvent(Request::create('/api/logout', 'POST'), null));
    }

    /**
     * Le firewall (Security\Http\Firewall\ExceptionListener, priorité 1)
     * enveloppe le refus d'accès dans une AccessDeniedHttpException dont le
     * `previous` est l'AccessDeniedException d'origine : c'est cette paire
     * qu'on reconnaît, à priorité 0.
     */
    public function testAnAccessDeniedOnTheBackofficeIsLogged(): void
    {
        $this->auditLogger()->expects(self::once())->method('backofficeAccessDenied');

        $this->subscriber()->onKernelException($this->exceptionEvent(
            '/api/backoffice/users',
            new AccessDeniedHttpException('Access Denied.', new AccessDeniedException()),
        ));
    }

    /**
     * Issue #77 : le chemin est comparé décodé, comme le firewall le voit.
     */
    public function testAPercentEncodedBackofficePathIsRecognised(): void
    {
        $this->auditLogger()->expects(self::once())->method('backofficeAccessDenied');

        $this->subscriber()->onKernelException($this->exceptionEvent(
            '/api/%62ackoffice/users',
            new AccessDeniedHttpException('Access Denied.', new AccessDeniedException()),
        ));
    }

    /**
     * Les deux gardes CSRF lèvent la même classe HTTP, sans `previous` : ce
     * rejet est déjà journalisé comme `csrf-rejected`, il ne doit pas sortir
     * une seconde fois sous un autre nom.
     */
    public function testACsrfRejectionOnTheBackofficeIsNotAnAccessDenied(): void
    {
        $this->auditLogger()->expects(self::never())->method('backofficeAccessDenied');

        $this->subscriber()->onKernelException($this->exceptionEvent(
            '/api/backoffice/users',
            new AccessDeniedHttpException('En-tête CSRF manquant ou invalide.'),
        ));
    }

    public function testAnAccessDeniedOutsideTheBackofficeIsIgnored(): void
    {
        $this->auditLogger()->expects(self::never())->method('backofficeAccessDenied');

        $this->subscriber()->onKernelException($this->exceptionEvent(
            '/api/cv',
            new AccessDeniedHttpException('Access Denied.', new AccessDeniedException()),
        ));
    }

    /**
     * `^/api/backoffice(/|$)`, comme la règle d'access_control (issue #78) :
     * un voisin `/api/backoffice-preview` n'est pas le backoffice.
     */
    public function testASiblingPathIsNotTheBackoffice(): void
    {
        $this->auditLogger()->expects(self::never())->method('backofficeAccessDenied');

        $this->subscriber()->onKernelException($this->exceptionEvent(
            '/api/backoffice-preview',
            new AccessDeniedHttpException('Access Denied.', new AccessDeniedException()),
        ));
    }

    public function testAnotherExceptionOnTheBackofficeIsIgnored(): void
    {
        $this->auditLogger()->expects(self::never())->method('backofficeAccessDenied');

        $this->subscriber()->onKernelException($this->exceptionEvent('/api/backoffice/users', new NotFoundHttpException()));
    }

    public function testASubRequestIsIgnored(): void
    {
        $this->auditLogger()->expects(self::never())->method('backofficeAccessDenied');

        $this->subscriber()->onKernelException($this->exceptionEvent(
            '/api/backoffice/users',
            new AccessDeniedHttpException('Access Denied.', new AccessDeniedException()),
            HttpKernelInterface::SUB_REQUEST,
        ));
    }

    /**
     * Priorité 0 sur kernel.exception : après le firewall (1), qui pose
     * l'enveloppe reconnue ci-dessus, et avant API Platform (-96), qui fixe
     * la réponse et arrête la propagation.
     */
    public function testKernelExceptionIsSubscribedBelowTheFirewallListener(): void
    {
        $subscribed = SecurityEventsSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelException', 0], $subscribed[KernelEvents::EXCEPTION]);
        self::assertArrayHasKey(LoginSuccessEvent::class, $subscribed);
        self::assertArrayHasKey(LoginFailureEvent::class, $subscribed);
        self::assertArrayHasKey(LogoutEvent::class, $subscribed);
    }

    private function loginSuccess(string $firewall, string $username): LoginSuccessEvent
    {
        $user = new CpgUser($username, 'hashed-password');

        return new LoginSuccessEvent(
            self::createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($username, static fn (): CpgUser => $user)),
            new UsernamePasswordToken($user, $firewall, $user->getRoles()),
            Request::create('/api/login_check', 'POST'),
            null,
            $firewall,
        );
    }

    private function loginFailure(string $firewall, AuthenticationException $exception, ?string $username): LoginFailureEvent
    {
        $passport = null === $username
            ? null
            : new Passport(new UserBadge($username, static fn (): CpgUser => new CpgUser($username, 'hashed-password')), new PasswordCredentials('irrelevant'));

        return new LoginFailureEvent(
            $exception,
            self::createStub(AuthenticatorInterface::class),
            Request::create('/api/login_check', 'POST'),
            null,
            $firewall,
            $passport,
        );
    }

    private function exceptionEvent(string $uri, \Throwable $throwable, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($uri),
            $requestType,
            $throwable,
        );
    }
}
