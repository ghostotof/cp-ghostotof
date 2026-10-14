<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Infrastructure\Log\LoginIdentifierFingerprint;
use App\Security\Authentication\Infrastructure\Log\SecurityAuditLogger;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\ValueObject\BaseAccessUser;
use App\Tests\Support\TestCredentials;
use Closure;
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
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * D5 (audit 2026-09-16, constat A5) : un seul point d'entrée pour le journal
 * de sécurité. Chaque méthode produit exactement un enregistrement `info`
 * portant un `event` stable en kebab-case, le sujet, l'auteur, l'IP et le
 * chemin — et rien d'autre. Un compte, sujet ou auteur, se désigne par son
 * UUID, jamais par son identifiant de connexion (issue #363). Le test
 * « valeurs sentinelles » pince la règle « jamais un secret, un e-mail ni
 * l'identifiant d'un compte dans un contexte ».
 *
 * Le `TestLogger` de psr/log n'existe plus dans psr/log 3 (déplacé dans
 * fig/log-test, non installé) : le TestHandler de Monolog joue le même rôle,
 * avec en prime le nom du canal et le niveau tels qu'ils sortiront en prod.
 */
final class SecurityAuditLoggerTest extends TestCase
{
    private const string IP = '203.0.113.7';

    private TestHandler $handler;
    private RequestStack $requestStack;
    private TokenStorage $tokenStorage;
    private LoginIdentifierFingerprint $fingerprint;
    private SecurityAuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->requestStack = new RequestStack();
        $this->tokenStorage = new TokenStorage();
        $this->fingerprint = new LoginIdentifierFingerprint('test-kernel-secret');
        $this->auditLogger = new SecurityAuditLogger(
            new Logger('security_audit', [$this->handler]),
            $this->requestStack,
            $this->tokenStorage,
            $this->fingerprint,
        );
    }

    public function testLoginSucceededNamesTheAccountByItsIdAsBothSubjectAndActor(): void
    {
        $this->pushRequest('/api/login_check', 'POST');
        $jane = new CpgUser('jane', 'hashed-password');
        $this->authenticate($jane);

        $this->auditLogger->loginSucceeded($jane);

        self::assertSame([
            'event' => 'login-succeeded',
            'userId' => $jane->accountId(),
            'actor' => $jane->accountId(),
            'ip' => self::IP,
            'path' => '/api/login_check',
        ], $this->singleRecord()->context);
    }

    /**
     * Issue #418 : un identifiant tenté qui ne désigne aucun compte n'est
     * jamais écrit tel quel — c'est souvent un mot de passe tapé dans le
     * mauvais champ. Seule son empreinte sort, pour corréler les tentatives.
     */
    public function testLoginFailedOnAnUnknownIdentifierLogsItsFingerprintNeverTheValue(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->loginFailed(TestCredentials::variant('typed-as-identifier'), null);

        self::assertSame([
            'event' => 'login-failed',
            'userFingerprint' => $this->fingerprint->of(TestCredentials::variant('typed-as-identifier')),
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/login_check',
        ], $this->singleRecord()->context);
    }

    /**
     * Un compte existant est désigné, comme dans les autres événements, par
     * son UUID (issue #363) — celui du compte chargé, pas la saisie.
     */
    public function testLoginFailedOnAnExistingAccountNamesItByItsIdAndKeepsTheFingerprint(): void
    {
        $this->pushRequest('/api/login_check', 'POST');
        $jane = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->loginFailed('jane', $jane);

        self::assertSame([
            'event' => 'login-failed',
            'userId' => $jane->getId()->toRfc4122(),
            'userFingerprint' => $this->fingerprint->of('jane'),
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/login_check',
        ], $this->singleRecord()->context);
    }

    public function testLoginFailedWithoutIdentifierHasNeitherNameNorFingerprint(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->loginFailed(null, null);

        $context = $this->singleRecord()->context;
        self::assertSame('login-failed', $context['event']);
        self::assertArrayNotHasKey('user', $context);
        self::assertNull($context['userFingerprint']);
        self::assertArrayNotHasKey('userId', $context);
    }

    /**
     * Le throttling refuse avant que le compte ne soit chargé : l'appelant
     * n'en a pas, l'empreinte seule relie la ligne aux `login-failed` qui
     * l'ont déclenchée — y compris quand ceux-ci nommaient le compte.
     */
    public function testLoginThrottledIsItsOwnEventLinkedToTheFailuresByTheFingerprint(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->loginFailed('jane', new CpgUser('jane', 'hashed-password'));
        $this->auditLogger->loginThrottled('JANE');

        [$failed, $throttled] = $this->handler->getRecords();
        self::assertSame('login-throttled', $throttled->context['event']);
        self::assertArrayNotHasKey('user', $throttled->context);
        self::assertArrayNotHasKey('userId', $throttled->context);
        self::assertSame($failed->context['userFingerprint'], $throttled->context['userFingerprint'], 'Même compteur login_throttling (insensible à la casse), même empreinte.');
    }

    public function testLoggedOutNamesTheDepartingAccountByItsId(): void
    {
        $this->pushRequest('/api/logout', 'POST');
        $jane = new CpgUser('jane', 'hashed-password');
        $this->authenticate($jane);

        $this->auditLogger->loggedOut($jane);

        self::assertSame([
            'event' => 'logged-out',
            'userId' => $jane->accountId(),
            'actor' => $jane->accountId(),
            'ip' => self::IP,
            'path' => '/api/logout',
        ], $this->singleRecord()->context);
    }

    /** « Terminer cet accès » (issue #65) : le marqueur `guest-…`, aléatoire, ne désigne personne. */
    public function testLoggedOutNamesADepartingBaseTierGuestByItsMarker(): void
    {
        $this->pushRequest('/api/logout', 'POST');
        $baseAccessUser = new BaseAccessUser('guest-0123');
        $this->authenticate($baseAccessUser);

        $this->auditLogger->loggedOut($baseAccessUser);

        self::assertSame([
            'event' => 'logged-out',
            'user' => 'guest-0123',
            'actor' => 'guest-0123',
            'ip' => self::IP,
            'path' => '/api/logout',
        ], $this->singleRecord()->context);
    }

    public function testALogoutWithoutTokenHasNoSubject(): void
    {
        $this->pushRequest('/api/logout', 'POST');

        $this->auditLogger->loggedOut(null);

        self::assertSame([
            'event' => 'logged-out',
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/logout',
        ], $this->singleRecord()->context);
    }

    /**
     * Un utilisateur qui n'est ni un compte ni le palier de base (aucun
     * fournisseur n'en produit aujourd'hui) : son identifiant, dont on ne sait
     * rien, n'est écrit ni comme sujet ni comme auteur.
     */
    public function testAnyOtherUserIsNeverNamedByItsIdentifier(): void
    {
        $this->pushRequest('/api/logout', 'POST');
        $other = new InMemoryUser('jean.dupont', null, ['ROLE_USER']);
        $this->authenticate($other);

        $this->auditLogger->loggedOut($other);

        self::assertSame([
            'event' => 'logged-out',
            'actor' => 'unidentified',
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
        $super = $this->authenticatedSuper();

        $this->auditLogger->csrfRejected();

        self::assertSame([
            'event' => 'csrf-rejected',
            'actor' => $super->accountId(),
            'ip' => self::IP,
            'path' => '/api/backoffice/users',
        ], $this->singleRecord()->context);
    }

    public function testBackofficeAccessDeniedNamesTheBaseTierGuestAsActor(): void
    {
        $this->pushRequest('/api/backoffice/users', 'GET');
        $this->authenticate(new BaseAccessUser('guest-0123'));

        $this->auditLogger->backofficeAccessDenied();

        self::assertSame([
            'event' => 'backoffice-access-denied',
            'actor' => 'guest-0123',
            'ip' => self::IP,
            'path' => '/api/backoffice/users',
        ], $this->singleRecord()->context);
    }

    /**
     * Issue #276 : la panne du verrou des limiteurs ne nomme ni le limiteur ni
     * sa clé (une IP, un identifiant tenté) — le chemin dit lequel a cédé.
     */
    public function testRateLimiterUnavailableCarriesNoSubjectOnlyTheActorAndThePath(): void
    {
        $this->pushRequest('/api/login_check', 'POST');

        $this->auditLogger->rateLimiterUnavailable();

        self::assertSame([
            'event' => 'rate-limiter-unavailable',
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/login_check',
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

    public function testUserInvitedNamesTheAccountAndTheActingAdminByTheirIds(): void
    {
        $this->pushRequest('/api/backoffice/users', 'POST');
        $super = $this->authenticatedSuper();
        $invited = new CpgUser('jean.dupont', '');
        $invited->setEmail('jean.dupont@example.com');

        $this->auditLogger->userInvited($invited);

        self::assertSame([
            'event' => 'user-invited',
            'userId' => $invited->getId()->toRfc4122(),
            'actor' => $super->accountId(),
            'ip' => self::IP,
            'path' => '/api/backoffice/users',
        ], $this->singleRecord()->context);
    }

    public function testUserReinvitedIsItsOwnEvent(): void
    {
        $this->pushRequest('/api/backoffice/users/x/invitation', 'POST');
        $this->authenticatedSuper();
        $invited = new CpgUser('jean.dupont', '');

        $this->auditLogger->userReinvited($invited);

        self::assertSame('user-reinvited', $this->singleRecord()->context['event']);
        self::assertSame($invited->accountId(), $this->singleRecord()->context['userId']);
        self::assertArrayNotHasKey('user', $this->singleRecord()->context);
    }

    #[DataProvider('roleChanges')]
    public function testRoleChangedSaysWhetherSuperAdminWasGrantedOrRevoked(bool $superAdmin): void
    {
        $this->pushRequest('/api/backoffice/users/x/roles', 'PUT');
        $super = $this->authenticatedSuper();
        $target = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->roleChanged($target, $superAdmin);

        self::assertSame([
            'event' => 'role-changed',
            'userId' => $target->getId()->toRfc4122(),
            'superAdmin' => $superAdmin,
            'actor' => $super->accountId(),
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
        $super = $this->authenticatedSuper();
        $target = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->passwordChanged($target);

        self::assertSame([
            'event' => 'password-changed',
            'userId' => $target->getId()->toRfc4122(),
            'actor' => $super->accountId(),
            'ip' => self::IP,
            'path' => '/api/backoffice/users/x/password',
        ], $this->singleRecord()->context);
    }

    public function testUserDeletedNamesTheRemovedAccount(): void
    {
        $this->pushRequest('/api/backoffice/users/x', 'DELETE');
        $super = $this->authenticatedSuper();
        $target = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->userDeleted($target);

        self::assertSame([
            'event' => 'user-deleted',
            'userId' => $target->getId()->toRfc4122(),
            'actor' => $super->accountId(),
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
            'userId' => $target->getId()->toRfc4122(),
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/account/password-setup',
        ], $this->singleRecord()->context);
    }

    /**
     * Issue #356 : un jeton inconnu ne nomme rien — ni le jeton (un secret,
     * même faux : une faute de frappe sur un vrai lien en serait proche), ni
     * un compte (il n'y en a pas). L'IP et le chemin suffisent à attribuer un
     * essai de jetons à la chaîne.
     */
    public function testPasswordSetupTokenRejectedCarriesNoSubjectOnlyTheIpAndThePath(): void
    {
        $this->pushRequest('/api/account/password-setup/validate', 'POST');

        $this->auditLogger->passwordSetupTokenRejected();

        self::assertSame([
            'event' => 'password-setup-token-rejected',
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/account/password-setup/validate',
        ], $this->singleRecord()->context);
    }

    /**
     * Issue #356 : un lien déjà consommé qui revient est le signe possible
     * d'une fuite (boîte mail lue par un tiers, lien transféré). Le compte
     * visé est nommé — c'est lui qu'il faut prévenir —, jamais le jeton.
     */
    public function testPasswordSetupTokenReplayedNamesTheAccountTheLinkWasFor(): void
    {
        $this->pushRequest('/api/account/password-setup', 'POST');
        $target = new CpgUser('jane', 'hashed-password');

        $this->auditLogger->passwordSetupTokenReplayed($target);

        self::assertSame([
            'event' => 'password-setup-token-replayed',
            'userId' => $target->getId()->toRfc4122(),
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => '/api/account/password-setup',
        ], $this->singleRecord()->context);
    }

    /**
     * Issue #356 : un quota anonyme atteint n'a pas de sujet — la clé du
     * limiteur est l'IP, déjà dans la ligne, et le chemin dit lequel.
     */
    #[DataProvider('anonymousQuotaRefusals')]
    public function testAnAnonymousQuotaRefusalCarriesNoSubjectOnlyTheIpAndThePath(Closure $refuse, string $path, string $event): void
    {
        $this->pushRequest($path, 'POST');

        $refuse($this->auditLogger);

        self::assertSame([
            'event' => $event,
            'actor' => 'anonymous',
            'ip' => self::IP,
            'path' => $path,
        ], $this->singleRecord()->context);
    }

    /**
     * @return iterable<string, array{Closure(SecurityAuditLogger):void, string, string}>
     */
    public static function anonymousQuotaRefusals(): iterable
    {
        yield 'définition de mot de passe' => [static fn (SecurityAuditLogger $logger) => $logger->passwordSetupThrottled(), '/api/account/password-setup/validate', 'password-setup-throttled'];
        yield 'formulaire de contact' => [static fn (SecurityAuditLogger $logger) => $logger->contactThrottled(), '/api/contact', 'contact-throttled'];
        yield 'palier de base' => [static fn (SecurityAuditLogger $logger) => $logger->baseAccessThrottled(), '/api/account/base-access', 'base-access-throttled'];
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
     * La purge (issue #238) est déclenchée par une commande CLI planifiée,
     * jamais par une action humaine : l'acteur est donc `system`, jamais le
     * jeton de sécurité de la requête courante (il n'y en a pas) ni
     * `anonymous` (qui dirait « quelqu'un de non identifié », faux ici).
     */
    public function testUserPurgedNamesTheAccountAndTheReasonWithSystemAsActor(): void
    {
        $target = new CpgUser('stale-invitee', '');

        $this->auditLogger->userPurged($target);

        self::assertSame([
            'event' => 'user-purged',
            'userId' => $target->getId()->toRfc4122(),
            'reason' => 'invitation-expired',
            'actor' => 'system',
            'ip' => null,
            'path' => null,
        ], $this->singleRecord()->context);
    }

    /**
     * Issue #386 : un compte créé en ligne de commande (app:user:create), y
     * compris un ROLE_SUPER, ne laissait aucune trace. L'acteur est `console`
     * — une personne au terminal, que le journal ne sait pas nommer — et non
     * `system`, réservé aux traitements planifiés, ni `anonymous`.
     *
     * @param list<string> $roles
     */
    #[DataProvider('createdAccountRoles')]
    public function testUserCreatedNamesTheAccountWhetherItIsSuperAdminAndTheConsoleAsActor(array $roles, bool $superAdmin): void
    {
        $created = new CpgUser('bootstrap', 'hashed-password');
        $created->setRoles($roles);

        $this->auditLogger->userCreated($created);

        self::assertSame([
            'event' => 'user-created',
            'userId' => $created->getId()->toRfc4122(),
            'superAdmin' => $superAdmin,
            'actor' => 'console',
            'ip' => null,
            'path' => null,
        ], $this->singleRecord()->context);
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function createdAccountRoles(): iterable
    {
        yield 'palier de base' => [[], false];
        yield 'super-administrateur' => [[CpgUser::ROLE_SUPER], true];
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

        $this->auditLogger->loginFailed('jane', null);

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
            // Issue #418 : un mot de passe tapé dans le champ identifiant.
            'password typed as identifier' => 'SENTINEL-TYPED-AS-USERNAME-3e6f',
            // Issue #363 : l'identifiant de connexion d'un compte, dérivé de
            // son adresse pour un invité — auteur comme sujet.
            'actor login identifier' => 'sentinel-admin-ident-6a1f',
            'subject login identifier' => 'sentinel-invitee-ident-1c8d',
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

        $actor = new CpgUser($sentinels['actor login identifier'], $sentinels['hashed password']);
        $actor->setEmail('super.'.$sentinels['email']);
        $this->authenticate($actor);

        $invited = new CpgUser($sentinels['subject login identifier'], $sentinels['hashed password']);
        $invited->setEmail($sentinels['email']);

        // Un login raté, un rejet CSRF, une invitation — plus le reste de
        // l'API, pour que l'ajout d'une méthode ne passe pas sous le radar.
        $this->auditLogger->loginFailed($sentinels['password typed as identifier'], null);
        $this->auditLogger->loginThrottled($sentinels['password typed as identifier']);
        $this->auditLogger->loginFailed('jane', $invited);
        $this->auditLogger->loginSucceeded($invited);
        $this->auditLogger->loggedOut($invited);
        $this->auditLogger->baseAccessIssued('guest-0123');
        $this->auditLogger->csrfRejected();
        $this->auditLogger->backofficeAccessDenied();
        $this->auditLogger->rateLimiterUnavailable();
        $this->auditLogger->userInvited($invited);
        $this->auditLogger->userReinvited($invited);
        $this->auditLogger->roleChanged($invited, true);
        $this->auditLogger->passwordChanged($invited);
        $this->auditLogger->userDeleted($invited);
        $this->auditLogger->accountActivated($invited);
        $this->auditLogger->userPurged($invited);
        $this->auditLogger->userCreated($invited);
        $this->auditLogger->passwordSetupTokenRejected();
        $this->auditLogger->passwordSetupTokenReplayed($invited);
        $this->auditLogger->passwordSetupThrottled();
        $this->auditLogger->contactThrottled();
        $this->auditLogger->baseAccessThrottled();

        $records = $this->handler->getRecords();
        self::assertCount(22, $records);

        foreach ($records as $record) {
            $serialized = json_encode([$record->message, $record->context, $record->extra], \JSON_THROW_ON_ERROR);

            foreach ($sentinels as $label => $sentinel) {
                self::assertStringNotContainsStringIgnoringCase(
                    $sentinel,
                    $serialized,
                    \sprintf('L\'enregistrement « %s » laisse fuir : %s.', $record->context['event'] ?? $record->message, $label),
                );
            }
        }
    }

    private function pushRequest(string $uri, string $method): void
    {
        $this->requestStack->push(Request::create($uri, $method, server: ['REMOTE_ADDR' => self::IP]));
    }

    private function authenticatedSuper(): CpgUser
    {
        $super = new CpgUser('super', 'hashed-password');
        $super->setRoles([CpgUser::ROLE_SUPER]);
        $this->authenticate($super);

        return $super;
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
