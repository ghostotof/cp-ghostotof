<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\InvitesUsers;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Journal de sécurité de bout en bout (D5, constat A5 du 3e audit) : chaque
 * événement clé traverse la vraie pile — firewall, listeners, API Platform —
 * et laisse exactement la ligne attendue sur le canal `security_audit`.
 *
 * Les tests unitaires (Infrastructure/Log/) pincent le contenu des lignes et
 * le tri des événements ; ici on vérifie que le câblage réel les produit :
 * un subscriber non enregistré, un firewall renommé ou une priorité qui
 * passe derrière API Platform seraient invisibles autrement.
 */
final class SecurityAuditLogTest extends WebTestCase
{
    use HttpJson;
    use InvitesUsers;
    use ReadsSecurityAuditLog;

    private const string SUPER_USERNAME = 'super';
    private const string PLAIN_USERNAME = 'jane';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAFailedLoginIsRecorded(): void
    {
        $client = $this->clientWithPlainUser();

        $this->attemptLogin($client, self::PLAIN_USERNAME, 'wrong-password');

        self::assertResponseStatusCodeSame(401);
        self::assertSame([
            'event' => 'login-failed',
            'user' => self::PLAIN_USERNAME,
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/login_check',
        ], self::singleSecurityAuditEvent('login-failed'));
        self::assertSame([], self::securityAuditEvents('login-succeeded'));
    }

    /**
     * Le compteur de login_throttling est indexé par (IP, identifiant) et
     * survit dans var/cache : identifiant unique par exécution, comme
     * LoginThrottlingTest.
     */
    public function testASixthFailedLoginIsRecordedAsThrottledNotAsFailed(): void
    {
        $username = 'throttle-'.bin2hex(random_bytes(6));
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register($username, TestCredentials::plainPassword());

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attemptLogin($client, $username, 'wrong-password');
            self::assertSame($username, self::singleSecurityAuditEvent('login-failed')['user']);
        }

        $this->attemptLogin($client, $username, 'wrong-password');

        self::assertResponseStatusCodeSame(401);
        self::assertSame($username, self::singleSecurityAuditEvent('login-throttled')['user']);
        self::assertSame([], self::securityAuditEvents('login-failed'), 'Une tentative bloquée par le throttling n\'est pas un login raté : le mot de passe n\'a même pas été vérifié.');
    }

    public function testASuccessfulLoginThenALogoutAreRecorded(): void
    {
        $client = $this->clientWithPlainUser();

        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        self::assertSame([
            'event' => 'login-succeeded',
            'user' => self::PLAIN_USERNAME,
            'actor' => self::PLAIN_USERNAME,
            'ip' => '127.0.0.1',
            'path' => '/api/login_check',
        ], self::singleSecurityAuditEvent('login-succeeded'));

        $client->request('POST', '/api/logout', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([
            'event' => 'logged-out',
            'user' => self::PLAIN_USERNAME,
            'actor' => self::PLAIN_USERNAME,
            'ip' => '127.0.0.1',
            'path' => '/api/logout',
        ], self::singleSecurityAuditEvent('logged-out'));
    }

    /**
     * Le firewall `api` ré-authentifie le JWT à chaque requête : ce n'est pas
     * un login, et le journal ne doit pas le dire.
     */
    public function testAnAuthenticatedApiCallIsNotRecordedAsALogin(): void
    {
        $client = $this->clientWithPlainUser();
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::securityAuditEvents('login-succeeded'));
    }

    public function testIssuingABaseAccessTokenIsRecorded(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertResponseIsSuccessful();
        $event = self::singleSecurityAuditEvent('base-access-issued');
        self::assertIsString($event['user']);
        self::assertStringStartsWith('guest-', $event['user']);
        self::assertSame('anonymous', $event['actor']);
        self::assertSame('/api/account/base-access', $event['path']);
    }

    /**
     * Le garde CSRF tourne à la priorité 20, AVANT le firewall (8) : au
     * moment du rejet, personne n'est encore authentifié, même si un cookie
     * BEARER valide accompagne la requête. L'auteur est donc « anonymous » —
     * ce qui est exact : la requête a été refusée avant toute authentification.
     */
    public function testACsrfRejectionOnTheBackofficeIsRecordedOnce(): void
    {
        $client = $this->clientWithSuperUser();
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/users', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'email' => 'newcomer@example.com',
            'locale' => 'fr',
        ]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([
            'event' => 'csrf-rejected',
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/backoffice/users',
        ], self::singleSecurityAuditEvent('csrf-rejected'));
        self::assertSame([], self::securityAuditEvents('backoffice-access-denied'), 'Un rejet CSRF n\'est pas un refus d\'autorisation : une seule ligne.');
    }

    public function testALoginWithoutTheRequestedWithHeaderIsRecordedAsACsrfRejection(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => self::PLAIN_USERNAME,
            'password' => 'irrelevant',
        ]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/api/login_check', self::singleSecurityAuditEvent('csrf-rejected')['path']);
        self::assertSame([], self::securityAuditEvents('login-failed'));
    }

    public function testABaseTierTokenOnTheBackofficeIsRecordedAsAccessDenied(): void
    {
        $client = self::createClient();
        $guest = $this->obtainBaseAccess($client);

        $client->request('GET', '/api/backoffice/users');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([
            'event' => 'backoffice-access-denied',
            'actor' => $guest,
            'ip' => '127.0.0.1',
            'path' => '/api/backoffice/users',
        ], self::singleSecurityAuditEvent('backoffice-access-denied'));
    }

    public function testAnAccountWithoutRoleSuperOnTheBackofficeIsRecordedAsAccessDenied(): void
    {
        $client = $this->clientWithPlainUser();
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/backoffice/users');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(self::PLAIN_USERNAME, self::singleSecurityAuditEvent('backoffice-access-denied')['actor']);
    }

    /**
     * Un anonyme est renvoyé au point d'entrée (401) par le firewall, qui fixe
     * la réponse et arrête la propagation avant notre listener : ce cas n'est
     * pas un 403 et n'est pas journalisé comme tel. Pin du périmètre, pour
     * qu'un changement de priorité ne le fasse pas basculer sans qu'on le voie.
     */
    public function testAnAnonymousCallOnTheBackofficeIsNotRecordedAsAccessDenied(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/users');

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], self::securityAuditEvents('backoffice-access-denied'));
    }

    public function testARoleSuperAccountOnTheBackofficeProducesNoDenial(): void
    {
        $client = $this->clientWithSuperUser();
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('GET', '/api/backoffice/users');

        self::assertResponseIsSuccessful();
        self::assertSame([], self::securityAuditEvents('backoffice-access-denied'));
        self::assertSame([], self::securityAuditEvents('csrf-rejected'));
    }

    // ----- 3.2b : actions d'administration et activation, avec l'auteur -----

    public function testInvitingAUserIsRecordedWithTheAccountIdAndTheActingAdmin(): void
    {
        $client = $this->clientWithSuperUser();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'jean.dupont@example.com', 'locale' => 'fr']));

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        self::assertSame([
            'event' => 'user-invited',
            'user' => 'jean.dupont',
            'userId' => $body['id'],
            'actor' => self::SUPER_USERNAME,
            'ip' => '127.0.0.1',
            'path' => '/api/backoffice/users',
        ], self::singleSecurityAuditEvent('user-invited'));
    }

    public function testResendingAnInvitationIsRecorded(): void
    {
        $client = $this->clientWithSuperUser();
        $invited = self::getContainer()->get(CpgUserInviterInterface::class)->invite('jean.dupont@example.com', Locale::FR);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', sprintf('/api/backoffice/users/%s/invitation', $invited->getId()->toRfc4122()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['locale' => 'en']));

        self::assertResponseStatusCodeSame(202);
        $event = self::singleSecurityAuditEvent('user-reinvited');
        self::assertSame('jean.dupont', $event['user']);
        self::assertSame($invited->getId()->toRfc4122(), $event['userId']);
        self::assertSame(self::SUPER_USERNAME, $event['actor']);
    }

    public function testGrantingSuperAdminIsRecordedWithTheDirection(): void
    {
        $client = $this->clientWithSuperUser();
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', sprintf('/api/backoffice/users/%s/roles', $jane->getId()->toRfc4122()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['superAdmin' => true]));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([
            'event' => 'role-changed',
            'user' => self::PLAIN_USERNAME,
            'userId' => $jane->getId()->toRfc4122(),
            'superAdmin' => true,
            'actor' => self::SUPER_USERNAME,
            'ip' => '127.0.0.1',
            'path' => sprintf('/api/backoffice/users/%s/roles', $jane->getId()->toRfc4122()),
        ], self::singleSecurityAuditEvent('role-changed'));
    }

    public function testChangingAPasswordIsRecordedWithoutThePassword(): void
    {
        $client = $this->clientWithSuperUser();
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', sprintf('/api/backoffice/users/%s/password', $jane->getId()->toRfc4122()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['password' => TestCredentials::variant('new')]));

        self::assertResponseStatusCodeSame(204);
        $event = self::singleSecurityAuditEvent('password-changed');
        self::assertSame(self::PLAIN_USERNAME, $event['user']);
        self::assertSame($jane->getId()->toRfc4122(), $event['userId']);
        self::assertSame(self::SUPER_USERNAME, $event['actor']);
        self::assertStringNotContainsString(TestCredentials::variant('new'), json_encode(self::securityAuditRecords(), \JSON_THROW_ON_ERROR));
    }

    public function testDeletingAUserIsRecorded(): void
    {
        $client = $this->clientWithSuperUser();
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('DELETE', sprintf('/api/backoffice/users/%s', $jane->getId()->toRfc4122()), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(204);
        $event = self::singleSecurityAuditEvent('user-deleted');
        self::assertSame(self::PLAIN_USERNAME, $event['user']);
        self::assertSame($jane->getId()->toRfc4122(), $event['userId']);
        self::assertSame(self::SUPER_USERNAME, $event['actor']);
    }

    /**
     * L'activation est anonyme (lien e-mail) et le jeton voyage dans le corps
     * (A7, D6) : la ligne nomme le compte, jamais le jeton — ni dans le chemin,
     * ni ailleurs dans l'enregistrement.
     */
    public function testActivatingAnAccountIsRecordedAnonymouslyWithoutTheToken(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $token = $this->inviteAndCollectSetupToken('newcomer@example.com', Locale::FR);

        $client->request('POST', '/api/account/password-setup', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => $token, 'password' => TestCredentials::variant('setup')]));

        self::assertResponseStatusCodeSame(204);
        $event = self::singleSecurityAuditEvent('account-activated');
        self::assertSame('newcomer', $event['user']);
        self::assertSame('anonymous', $event['actor']);
        self::assertSame('/api/account/password-setup', $event['path']);
        $this->assertTokenAppearsInNoSecurityAuditRecord($token);
    }

    /**
     * Les refus du parcours (jeton inconnu, expiré, corps invalide) et la
     * simple validation n'écrivent rien dans le journal d'audit — et surtout
     * pas le jeton reçu, qu'il soit bon ou mauvais.
     */
    public function testNoPasswordSetupRequestEverLeaksItsTokenIntoTheAuditLog(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $token = $this->inviteAndCollectSetupToken('newcomer@example.com', Locale::FR);
        $unknown = bin2hex(random_bytes(32));
        $server = ['CONTENT_TYPE' => 'application/json'];

        // Le kernel redémarre entre deux requêtes : le TestHandler ne détient
        // que les enregistrements de la dernière, on vérifie donc après chacune.
        $client->request('POST', '/api/account/password-setup/validate', server: $server, content: self::jsonBody(['token' => $token]));
        self::assertResponseStatusCodeSame(204);
        $this->assertTokenAppearsInNoSecurityAuditRecord($token);

        $client->request('POST', '/api/account/password-setup/validate', server: $server, content: self::jsonBody(['token' => $unknown]));
        self::assertResponseStatusCodeSame(404);
        $this->assertTokenAppearsInNoSecurityAuditRecord($unknown);

        $client->request('POST', '/api/account/password-setup', server: $server, content: self::jsonBody(['token' => $token, 'password' => 'short']));
        self::assertResponseStatusCodeSame(422);
        $this->assertTokenAppearsInNoSecurityAuditRecord($token);

        $client->request('POST', '/api/account/password-setup', server: $server, content: self::jsonBody(['token' => $unknown, 'password' => TestCredentials::variant('setup')]));
        self::assertResponseStatusCodeSame(404);
        $this->assertTokenAppearsInNoSecurityAuditRecord($unknown);
    }

    private function assertTokenAppearsInNoSecurityAuditRecord(string $token): void
    {
        self::assertStringNotContainsString($token, json_encode(self::securityAuditRecords(), \JSON_THROW_ON_ERROR));
    }

    private function clientWithPlainUser(): KernelBrowser
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());

        return $client;
    }

    private function clientWithSuperUser(): KernelBrowser
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);

        return $client;
    }

    private function attemptLogin(KernelBrowser $client, string $username, string $password): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $this->attemptLogin($client, $username, $password);
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }

    /**
     * Renvoie l'identifiant du jeton invité, tel que le journal doit le nommer.
     */
    private function obtainBaseAccess(KernelBrowser $client): string
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        $guest = self::singleSecurityAuditEvent('base-access-issued')['user'];
        self::assertIsString($guest);

        return $guest;
    }
}
