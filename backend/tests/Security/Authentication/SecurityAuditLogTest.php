<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\InvitesUsers;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
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
    use ReadsAllChannelsLog;
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

    /**
     * Un compte existant est nommé en clair, `userId` compris (issue #418) :
     * le nom vient du compte chargé, pas de la saisie. L'empreinte est là
     * aussi, c'est elle qui relie la ligne au `login-throttled` qui suivra.
     */
    public function testAFailedLoginIsRecorded(): void
    {
        $client = self::createClient();
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $this->attemptLogin($client, self::PLAIN_USERNAME, 'wrong-password');

        self::assertResponseStatusCodeSame(401);
        $event = self::singleSecurityAuditEvent('login-failed');
        self::assertIsString($event['userFingerprint']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{6}\z/', $event['userFingerprint']);
        self::assertSame([
            'event' => 'login-failed',
            'user' => self::PLAIN_USERNAME,
            'userId' => $jane->getId()->toRfc4122(),
            'userFingerprint' => $event['userFingerprint'],
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/login_check',
        ], $event);
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

        $fingerprints = [];

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attemptLogin($client, $username, 'wrong-password');
            $failed = self::singleSecurityAuditEvent('login-failed');
            self::assertSame($username, $failed['user']);
            $fingerprints[] = $failed['userFingerprint'];
        }

        $this->attemptLogin($client, $username, 'wrong-password');

        // Le 429 de l'issue #399 est levé après l'écriture du journal
        // (LoginThrottlingRefusalListener, priorité -200) : l'événement est
        // toujours là, une seule fois — ThrottledRequestAuditListener ne le
        // réécrit pas (ThrottledRequestAuditCoverageTest, TRACED_UPSTREAM).
        self::assertResponseStatusCodeSame(429);
        // Issue #418 : le throttling refuse avant de charger le compte, la
        // ligne ne le nomme donc pas ; l'empreinte la relie aux cinq échecs.
        $throttled = self::singleSecurityAuditEvent('login-throttled');
        self::assertNull($throttled['user']);
        self::assertSame(array_fill(0, 5, $throttled['userFingerprint']), $fingerprints, 'Le lien login-throttled ↔ identifiant passe par l\'empreinte.');
        self::assertSame([], self::securityAuditEvents('login-failed'), 'Une tentative bloquée par le throttling n\'est pas un login raté : le mot de passe n\'a même pas été vérifié.');
    }

    /**
     * Issue #418 : `login_throttling` compte l'identifiant en minuscules
     * ({@see \Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter}) ;
     * l'empreinte suit le même repli. Cinq essais
     * sous des casses différentes déclenchent le refus, et les six lignes
     * portent la même empreinte. Si Symfony changeait son repli, ce test
     * tomberait — avant que la corrélation ne se perde en silence.
     */
    public function testCaseVariantsShareOneCounterAndOneFingerprint(): void
    {
        // Non ASCII exprès : le repli doit être celui de mb_strtolower.
        $identifier = 'élodie-'.bin2hex(random_bytes(6));
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $fingerprints = [];

        foreach ([$identifier, mb_strtoupper($identifier), 'É'.mb_substr($identifier, 1), $identifier, mb_strtoupper($identifier)] as $variant) {
            $this->attemptLogin($client, $variant, 'wrong-password');
            self::assertResponseStatusCodeSame(401);
            $fingerprints[] = self::singleSecurityAuditEvent('login-failed')['userFingerprint'];
        }

        $this->attemptLogin($client, 'É'.mb_substr($identifier, 1), 'wrong-password');

        self::assertResponseStatusCodeSame(429);
        self::assertSame(array_fill(0, 5, self::singleSecurityAuditEvent('login-throttled')['userFingerprint']), $fingerprints);
    }

    /**
     * Issue #418 : un mot de passe tapé dans le champ identifiant (erreur
     * classique, ou identifiant et mot de passe collés) ne doit pas sortir
     * dans la ligne `login-failed`. Elle ne nomme aucun compte et porte
     * l'empreinte de la saisie, pas la saisie.
     *
     * La valeur respecte exprès `CpgUser::USERNAME_PATTERN` : c'est la forme
     * d'un mot de passe généré par un trousseau (lettres, chiffres, tirets),
     * que le filtrage par motif envisagé dans l'issue laisserait passer.
     */
    public function testAPasswordTypedAsTheIdentifierIsNotInTheLoginFailedRecord(): void
    {
        $client = $this->clientWithPlainUser();
        self::getContainer()->get('cache.rate_limiter')->clear();

        // Le cas que le filtrage par motif laisserait passer : la valeur
        // respecte CpgUser::USERNAME_PATTERN.
        self::assertMatchesRegularExpression(CpgUser::USERNAME_PATTERN, $this->typedAsIdentifier());
        $this->attemptLogin($client, $this->typedAsIdentifier(), 'wrong-password');

        self::assertResponseStatusCodeSame(401);
        $event = self::singleSecurityAuditEvent('login-failed');
        self::assertNull($event['user']);
        self::assertArrayNotHasKey('userId', $event);
        self::assertIsString($event['userFingerprint']);
        self::assertStringNotContainsStringIgnoringCase($this->typedAsIdentifier(), json_encode($event, \JSON_THROW_ON_ERROR));
    }

    /**
     * Issue #418, l'autre moitié : le même mot de passe ne doit sortir par
     * **aucun** journal. Le canal `security` de Symfony journalise en `info`
     * — donc en production, où son handler est à `info` en dur — l'exception
     * du fournisseur d'utilisateurs ; celle du fournisseur `entity` recopiait
     * l'identifiant dans son message (`User "…" not found.`).
     *
     * Chaque enregistrement est formaté par le JsonFormatter de Monolog, celui
     * de `monolog.formatter.json` en production : c'est lui qui recopie le
     * message d'une exception placée dans le contexte, ce qu'un `json_encode`
     * du contexte (objet sans propriété publique → `{}`) ne montrerait pas.
     *
     * Deux chemins : l'échec d'un identifiant inconnu, et le refus du
     * throttling au sixième essai (autre exception, autre ligne d'audit). La
     * sonde ne garde que la dernière requête : chaque essai est relu.
     */
    #[DataProvider('attemptsUntilTheCheckedRefusal')]
    public function testAPasswordTypedAsTheIdentifierReachesNoLog(int $attempts, int $lastStatus): void
    {
        $client = $this->clientWithPlainUser();
        self::getContainer()->get('cache.rate_limiter')->clear();

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $this->attemptLogin($client, $this->typedAsIdentifier(), 'wrong-password');
            $this->assertNoLogQuotesThePasswordTypedAsTheIdentifier();
        }

        self::assertResponseStatusCodeSame($lastStatus);
    }

    /** @return iterable<string, array{int, int}> */
    public static function attemptsUntilTheCheckedRefusal(): iterable
    {
        yield 'identifiant inconnu' => [1, 401];
        yield 'refus du throttling' => [6, 429];
    }

    private function assertNoLogQuotesThePasswordTypedAsTheIdentifier(): void
    {
        $formatter = new JsonFormatter();

        foreach (self::allChannelsLogRecords() as $record) {
            // Les requêtes SQL et leurs paramètres (`doctrine`, debug) ne sont
            // journalisées que sous `kernel.debug` (`dbal.logging` vaut
            // `%kernel.debug%` par défaut) : jamais en préprod ni en prod,
            // toutes deux à APP_DEBUG=0 (docker/php/Dockerfile). Seulement
            // elles : un enregistrement `doctrine` d'un autre niveau reste lu.
            if ('doctrine' === $record->channel && Level::Debug === $record->level) {
                continue;
            }

            self::assertStringNotContainsStringIgnoringCase(
                $this->typedAsIdentifier(),
                $formatter->format($record),
                \sprintf('Canal %s : « %s » recopie l\'identifiant tenté.', $record->channel, $record->message),
            );
        }
    }

    /**
     * Nommer le compte d'un login raté ne coûte aucune requête (issue #418) :
     * {@see \Symfony\Component\Security\Http\EventListener\CheckCredentialsListener}
     * l'a déjà chargé,
     * {@see \Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge}
     * le mémorise, et
     * {@see \App\Security\Authentication\Infrastructure\Log\SecurityEventsSubscriber}
     * ne fait que le relire. Une seule lecture de
     * `cpg_user` pour toute la requête.
     */
    public function testNamingTheAccountOfAFailedLoginCostsNoExtraQuery(): void
    {
        $client = $this->clientWithPlainUser();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->attemptLogin($client, self::PLAIN_USERNAME, 'wrong-password');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(self::PLAIN_USERNAME, self::singleSecurityAuditEvent('login-failed')['user']);

        // La sonde garde aussi la création du compte, faite dans le même
        // kernel avant la requête (vérification d'unicité) : on ne compte
        // qu'à partir de la première ligne du firewall.
        $records = self::allChannelsLogRecords();
        $firstSecurityRecord = array_search('security', array_map(static fn (LogRecord $record): string => $record->channel, $records), true);
        self::assertIsInt($firstSecurityRecord);
        self::assertCount(1, array_filter(
            \array_slice($records, $firstSecurityRecord),
            static fn (LogRecord $record): bool => 'doctrine' === $record->channel && str_contains($record->message, 'FROM cpg_user'),
        ), 'Une seule lecture du compte pendant le login : celle de CheckCredentialsListener.');
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

        $client->request('POST', \sprintf('/api/backoffice/users/%s/invitation', $invited->getId()->toRfc4122()), server: [
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

        $client->request('PUT', \sprintf('/api/backoffice/users/%s/roles', $jane->getId()->toRfc4122()), server: [
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
            'path' => \sprintf('/api/backoffice/users/%s/roles', $jane->getId()->toRfc4122()),
        ], self::singleSecurityAuditEvent('role-changed'));
    }

    public function testChangingAPasswordIsRecordedWithoutThePassword(): void
    {
        $client = $this->clientWithSuperUser();
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', \sprintf('/api/backoffice/users/%s/password', $jane->getId()->toRfc4122()), server: [
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

        $client->request('DELETE', \sprintf('/api/backoffice/users/%s', $jane->getId()->toRfc4122()), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);

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
     * Issue #356 : un jeton inconnu laisse une ligne attribuable (IP, chemin)
     * sur `security_audit`, sans le jeton — sur les deux routes du parcours.
     */
    public function testAnUnknownPasswordSetupTokenIsRecordedAsRejected(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $unknown = bin2hex(random_bytes(32));
        $server = ['CONTENT_TYPE' => 'application/json'];

        $client->request('POST', '/api/account/password-setup/validate', server: $server, content: self::jsonBody(['token' => $unknown]));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([
            'event' => 'password-setup-token-rejected',
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/account/password-setup/validate',
        ], self::singleSecurityAuditEvent('password-setup-token-rejected'));

        $client->request('POST', '/api/account/password-setup', server: $server, content: self::jsonBody(['token' => $unknown, 'password' => TestCredentials::variant('setup')]));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/api/account/password-setup', self::singleSecurityAuditEvent('password-setup-token-rejected')['path']);
    }

    /**
     * Issue #356 : un lien déjà consommé qui revient est tracé comme rejoué,
     * avec le compte qu'il activait. La réponse, elle, est celle de tout lien
     * inutilisable (404, issue #362) : seul le journal dit qu'il a servi.
     */
    public function testAReplayedPasswordSetupLinkIsRecordedWithItsAccount(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $token = $this->inviteAndCollectSetupToken('newcomer@example.com', Locale::FR);
        $server = ['CONTENT_TYPE' => 'application/json'];

        $client->request('POST', '/api/account/password-setup', server: $server, content: self::jsonBody(['token' => $token, 'password' => TestCredentials::variant('setup')]));
        self::assertResponseStatusCodeSame(204);
        $userId = self::singleSecurityAuditEvent('account-activated')['userId'];

        $client->request('POST', '/api/account/password-setup/validate', server: $server, content: self::jsonBody(['token' => $token]));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([
            'event' => 'password-setup-token-replayed',
            'user' => 'newcomer',
            'userId' => $userId,
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/account/password-setup/validate',
        ], self::singleSecurityAuditEvent('password-setup-token-replayed'));
        $this->assertTokenAppearsInNoSecurityAuditRecord($token);
    }

    /**
     * Aucune requête du parcours — validation, refus (jeton inconnu, corps
     * invalide) — ne recopie le jeton reçu dans le journal d'audit, qu'il soit
     * bon ou mauvais. Les refus y laissent une ligne depuis l'issue #356, sans
     * lui.
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

    // ----- Issue #356 : quotas par IP des routes anonymes -----

    /**
     * Le quota se consomme dans un écouteur kernel.request (audit C1) : le
     * 11e appel est refusé avant toute lecture du jeton, et c'est ce refus,
     * pas un jeton inconnu de plus, que la ligne doit dire.
     */
    public function testAPasswordSetupQuotaRefusalIsRecorded(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $body = self::jsonBody(['token' => bin2hex(random_bytes(32))]);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $client->request('POST', '/api/account/password-setup/validate', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('POST', '/api/account/password-setup/validate', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(429);
        self::assertSame([
            'event' => 'password-setup-throttled',
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/account/password-setup/validate',
        ], self::singleSecurityAuditEvent('password-setup-throttled'));
        self::assertSame([], self::securityAuditEvents('password-setup-token-rejected'));
    }

    public function testAContactQuotaRefusalIsRecorded(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $body = self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertResponseStatusCodeSame(202);
        }

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(429);
        self::assertSame([
            'event' => 'contact-throttled',
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/contact',
        ], self::singleSecurityAuditEvent('contact-throttled'));
        // Ni le corps ni l'adresse de l'expéditeur : la ligne ne dit que le refus.
        self::assertStringNotContainsString('jane@example.com', json_encode(self::securityAuditRecords(), \JSON_THROW_ON_ERROR));
    }

    /**
     * Rendu par ApiProblemResponseListener (-98), hors d'API Platform : le
     * même écouteur de kernel.exception doit le voir passer.
     */
    public function testABaseAccessQuotaRefusalIsRecorded(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame([
            'event' => 'base-access-throttled',
            'actor' => 'anonymous',
            'ip' => '127.0.0.1',
            'path' => '/api/account/base-access',
        ], self::singleSecurityAuditEvent('base-access-throttled'));
        self::assertSame([], self::securityAuditEvents('base-access-issued'));
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

    /**
     * Un mot de passe tapé dans le champ identifiant (issue #418) :
     * alphanumérique, comme la plupart des mots de passe, donc valide au regard
     * de CpgUser::USERNAME_PATTERN. Généré, jamais écrit en dur : un littéral
     * rangé sous un nom de mot de passe déclenche les détecteurs génériques de
     * secrets (cf. {@see TestCredentials}).
     */
    private function typedAsIdentifier(): string
    {
        return TestCredentials::variant('typed-as-identifier');
    }
}
