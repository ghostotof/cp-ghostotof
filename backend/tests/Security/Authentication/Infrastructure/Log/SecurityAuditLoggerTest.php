<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Infrastructure\Log\SecurityAuditLogger;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\ValueObject\GuestUser;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * D5 (audit 2026-09-16, constat A5) : un seul point d'entrée pour le journal
 * de sécurité. Chaque méthode produit exactement un enregistrement `info`
 * portant un `event` stable en kebab-case, l'identifiant visé, l'auteur, l'IP
 * et le chemin — et rien d'autre. Le test « valeurs sentinelles » pince la
 * règle « jamais un secret ni un e-mail dans un contexte ».
 *
 * `Psr\Log\Test\TestLogger` n'existe plus dans psr/log 3 (déplacé dans
 * fig/log-test, non installé) : le TestHandler de Monolog joue le même rôle,
 * avec en prime le nom du canal et le niveau tels qu'ils sortiront en prod.
 */
final class SecurityAuditLoggerTest extends TestCase
{
    private const string IP = '203.0.113.7';

    private TestHandler $handler;
    private RequestStack $requestStack;
    private TokenStorage $tokenStorage;
    private SecurityAuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->requestStack = new RequestStack();
        $this->tokenStorage = new TokenStorage();
        $this->auditLogger = new SecurityAuditLogger(
            new Logger('security_audit', [$this->handler]),
            $this->requestStack,
            $this->tokenStorage,
        );
    }

    public function testLoginSucceededNamesTheUserAsBothSubjectAndActor(): void
    {
        $this->pushRequest('/api/login_check', 'POST');
        $this->authenticate(new CpgUser('jane', 'hashed-password'));

        $this->auditLogger->loginSucceeded('jane');

        self::assertSame([
            'event' => 'login-succeeded',
            'user' => 'jane',
            'actor' => 'jane',
            'ip' => self::IP,
            'path' => '/api/login_check',
        ], $this->singleRecord()->context);
    }

    public function testLoginFailedIsAnonymousAndKeepsTheAttemptedIdentifier(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->loginFailed('jane');

        self::assertSame([
            'event' => 'login-failed',
            'user' => 'jane',
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/login_check',
        ], $this->singleRecord()->context);
    }

    public function testLoginFailedAcceptsAnUnknownIdentifier(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->loginFailed(null);

        self::assertNull($this->singleRecord()->context['user']);
        self::assertSame('login-failed', $this->singleRecord()->context['event']);
    }

    public function testLoginThrottledIsItsOwnEvent(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->loginThrottled('jane');

        self::assertSame('login-throttled', $this->singleRecord()->context['event']);
        self::assertSame('jane', $this->singleRecord()->context['user']);
    }

    public function testLoggedOutNamesTheDepartingUser(): void
    {
        $this->pushRequest('/api/logout', 'POST');
        $this->authenticate(new CpgUser('jane', 'hashed-password'));

        $this->auditLogger->loggedOut('jane');

        self::assertSame([
            'event' => 'logged-out',
            'user' => 'jane',
            'actor' => 'jane',
            'ip' => self::IP,
            'path' => '/api/logout',
        ], $this->singleRecord()->context);
    }

    public function testBaseAccessIssuedNamesTheGuestIdentifier(): void
    {
        $this->pushRequest('/api/account/base-access', 'POST');

        $this->auditLogger->baseAccessIssued('guest-0123');

        self::assertSame([
            'event' => 'base-access-issued',
            'user' => 'guest-0123',
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/account/base-access',
        ], $this->singleRecord()->context);
    }

    public function testCsrfRejectedCarriesNoSubjectOnlyTheActorAndThePath(): void
    {
        $this->pushRequest('/api/backoffice/users', 'POST');
        $this->authenticate(new CpgUser('super', 'hashed-password'));

        $this->auditLogger->csrfRejected();

        self::assertSame([
            'event' => 'csrf-rejected',
            'actor' => 'super',
            'ip' => self::IP,
            'path' => '/api/backoffice/users',
        ], $this->singleRecord()->context);
    }

    public function testBackofficeAccessDeniedNamesTheBaseTierGuestAsActor(): void
    {
        $this->pushRequest('/api/backoffice/users', 'GET');
        $this->authenticate(new GuestUser('guest-0123'));

        $this->auditLogger->backofficeAccessDenied();

        self::assertSame([
            'event' => 'backoffice-access-denied',
            'actor' => 'guest-0123',
            'ip' => self::IP,
            'path' => '/api/backoffice/users',
        ], $this->singleRecord()->context);
    }

    /**
     * Le chemin est journalisé sous sa forme décodée (CanonicalPath, issue
     * #77) : c'est celle que le routeur et le firewall ont réellement vue.
     */
    public function testThePathIsCanonical(): void
    {
        $this->pushRequest('/%61pi/backoffice/users', 'POST');

        $this->auditLogger->csrfRejected();

        self::assertSame('/api/backoffice/users', $this->singleRecord()->context['path']);
    }

    public function testUserInvitedNamesTheAccountByUsernameAndIdAndTheActingAdmin(): void
    {
        $this->pushRequest('/api/backoffice/users', 'POST');
        $this->authenticate(new CpgUser('super', 'hashed-password'));
        $invited = new CpgUser('jean.dupont', '');
        $invited->setEmail('jean.dupont@example.com');

        $this->auditLogger->userInvited($invited);

        self::assertSame([
            'event' => 'user-invited',
            'user' => 'jean.dupont',
            'userId' => $invited->getId()->toRfc4122(),
            'actor' => 'super',
            'ip' => self::IP,
            'path' => '/api/backoffice/users',
        ], $this->singleRecord()->context);
    }

    public function testUserReinvitedIsItsOwnEvent(): void
    {
        $this->pushRequest('/api/backoffice/users/x/invitation', 'POST');
        $this->authenticate(new CpgUser('super', 'hashed-password'));

        $this->auditLogger->userReinvited(new CpgUser('jean.dupont', ''));

        self::assertSame('user-reinvited', $this->singleRecord()->context['event']);
        self::assertSame('jean.dupont', $this->singleRecord()->context['user']);
    }

    #[DataProvider('roleChanges')]
    public function testRoleChangedSaysWhetherSuperAdminWasGrantedOrRevoked(bool $superAdmin): void
    {
        $this->pushRequest('/api/backoffice/users/x/roles', 'PUT');
        $this->authenticate(new CpgUser('super', 'hashed-password'));
        $target = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->roleChanged($target, $superAdmin);

        self::assertSame([
            'event' => 'role-changed',
            'user' => 'jane',
            'userId' => $target->getId()->toRfc4122(),
            'superAdmin' => $superAdmin,
            'actor' => 'super',
            'ip' => self::IP,
            'path' => '/api/backoffice/users/x/roles',
        ], $this->singleRecord()->context);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function roleChanges(): iterable
    {
        yield 'granted' => [true];
        yield 'revoked' => [false];
    }

    public function testPasswordChangedNeverSeesThePassword(): void
    {
        $this->pushRequest('/api/backoffice/users/x/password', 'PUT');
        $this->authenticate(new CpgUser('super', 'hashed-password'));
        $target = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->passwordChanged($target);

        self::assertSame([
            'event' => 'password-changed',
            'user' => 'jane',
            'userId' => $target->getId()->toRfc4122(),
            'actor' => 'super',
            'ip' => self::IP,
            'path' => '/api/backoffice/users/x/password',
        ], $this->singleRecord()->context);
    }

    public function testUserDeletedNamesTheRemovedAccount(): void
    {
        $this->pushRequest('/api/backoffice/users/x', 'DELETE');
        $this->authenticate(new CpgUser('super', 'hashed-password'));
        $target = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->userDeleted($target);

        self::assertSame([
            'event' => 'user-deleted',
            'user' => 'jane',
            'userId' => $target->getId()->toRfc4122(),
            'actor' => 'super',
            'ip' => self::IP,
            'path' => '/api/backoffice/users/x',
        ], $this->singleRecord()->context);
    }

    /**
     * L'activation est un parcours public (lien e-mail) : l'auteur est
     * anonyme, le compte visé est celui qui s'active.
     */
    public function testAccountActivatedIsPerformedAnonymously(): void
    {
        $this->pushRequest('/api/account/password-setup', 'POST');
        $target = new CpgUser('jane', '');

        $this->auditLogger->accountActivated($target);

        self::assertSame([
            'event' => 'account-activated',
            'user' => 'jane',
            'userId' => $target->getId()->toRfc4122(),
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/account/password-setup',
        ], $this->singleRecord()->context);
    }

    /**
     * Le chemin journalisé est le chemin canonique, tel quel : depuis T4.1
     * (audit A7, D6) plus aucune route ne porte de jeton dans son chemin, la
     * rédaction transitoire `…/password-setup/{token}` a donc disparu. Un
     * chemin n'est plus jamais réécrit — si une route devait un jour porter un
     * secret dans son URL, c'est la route qu'il faudrait corriger, pas le
     * journal.
     */
    public function testThePathIsLoggedCanonicalAndNeverRewritten(): void
    {
        $this->pushRequest('/api/account/password%2Dsetup/validate', 'POST');

        $this->auditLogger->csrfRejected();

        self::assertSame('/api/account/password-setup/validate', $this->singleRecord()->context['path']);
    }

    /**
     * Hors requête HTTP (commande, handler Messenger) : ni IP ni chemin, mais
     * l'événement sort quand même — l'absence de requête n'est pas une raison
     * de perdre la trace.
     */
    public function testWithoutARequestIpAndPathAreNull(): void
    {
        $this->auditLogger->userDeleted(new CpgUser('jane', 'hashed-password'));

        $context = $this->singleRecord()->context;
        self::assertNull($context['ip']);
        self::assertNull($context['path']);
        self::assertSame('anonymous', $context['actor']);
    }

    public function testEveryRecordIsAnInfoOnTheSecurityAuditChannel(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->loginFailed('jane');

        $record = $this->singleRecord();
        self::assertSame(Level::Info, $record->level);
        self::assertSame('security_audit', $record->channel);
        self::assertNotSame('', $record->message);
    }

    /**
     * La règle qui justifie ce logger : rien de ce qui transite dans une
     * requête sensible ne doit se retrouver dans un contexte. Des valeurs
     * sentinelles reconnaissables sont placées partout où un secret ou une
     * donnée personnelle peut circuler (corps, cookies, en-têtes, entité), et
     * chaque valeur de contexte de chaque enregistrement est passée au crible.
     */
    public function testNoContextValueEverCarriesAPasswordATokenOrAnEmail(): void
    {
        $sentinels = [
            'password' => 'SENTINEL-PASSWORD-9f1c',
            'hashed password' => 'SENTINEL-HASH-4b7e',
            'bearer JWT' => 'SENTINEL-JWT-2a9d',
            'XSRF cookie' => 'SENTINEL-XSRF-8c3f',
            'requested-with header' => 'SENTINEL-REQUESTED-WITH-5e1a',
            'invitation token' => 'SENTINEL-INVITATION-TOKEN-7d2b',
            'email' => 'sentinel.person@example.com',
        ];

        // Le jeton d'invitation voyage dans le corps (A7, D6), comme le mot de
        // passe : jamais dans le chemin, que le journal recopie tel quel.
        $request = Request::create(
            '/api/account/password-setup',
            'POST',
            server: [
                'REMOTE_ADDR' => self::IP,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_XSRF_TOKEN' => $sentinels['XSRF cookie'],
                'HTTP_X_REQUESTED_WITH' => $sentinels['requested-with header'],
                'HTTP_AUTHORIZATION' => 'Bearer '.$sentinels['bearer JWT'],
            ],
            content: json_encode(['username' => 'jane', 'password' => $sentinels['password'], 'token' => $sentinels['invitation token']], \JSON_THROW_ON_ERROR),
        );
        $request->cookies->set('BEARER', $sentinels['bearer JWT']);
        $request->cookies->set('XSRF-TOKEN', $sentinels['XSRF cookie']);
        $this->requestStack->push($request);

        $actor = new CpgUser('super', $sentinels['hashed password']);
        $actor->setEmail('super.'.$sentinels['email']);
        $this->authenticate($actor);

        $invited = new CpgUser('jean.dupont', $sentinels['hashed password']);
        $invited->setEmail($sentinels['email']);

        // Un login raté, un rejet CSRF, une invitation — plus le reste de
        // l'API, pour que l'ajout d'une méthode ne passe pas sous le radar.
        $this->auditLogger->loginFailed('jane');
        $this->auditLogger->loginThrottled('jane');
        $this->auditLogger->loginSucceeded('jane');
        $this->auditLogger->loggedOut('jane');
        $this->auditLogger->baseAccessIssued('guest-0123');
        $this->auditLogger->csrfRejected();
        $this->auditLogger->backofficeAccessDenied();
        $this->auditLogger->userInvited($invited);
        $this->auditLogger->userReinvited($invited);
        $this->auditLogger->roleChanged($invited, true);
        $this->auditLogger->passwordChanged($invited);
        $this->auditLogger->userDeleted($invited);
        $this->auditLogger->accountActivated($invited);

        $records = $this->handler->getRecords();
        self::assertCount(13, $records);

        foreach ($records as $record) {
            $serialized = json_encode([$record->message, $record->context, $record->extra], \JSON_THROW_ON_ERROR);

            foreach ($sentinels as $label => $sentinel) {
                self::assertStringNotContainsStringIgnoringCase(
                    $sentinel,
                    $serialized,
                    sprintf('L\'enregistrement « %s » laisse fuir : %s.', $record->context['event'] ?? $record->message, $label),
                );
            }
        }
    }

    private function pushRequest(string $uri, string $method): void
    {
        $this->requestStack->push(Request::create($uri, $method, server: ['REMOTE_ADDR' => self::IP]));
    }

    private function authenticate(UserInterface $user): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
    }

    private function singleRecord(): LogRecord
    {
        $records = $this->handler->getRecords();
        self::assertCount(1, $records, 'Exactement un enregistrement attendu.');

        return $records[0];
    }
}
