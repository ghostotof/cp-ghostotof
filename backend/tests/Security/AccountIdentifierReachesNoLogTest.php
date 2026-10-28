<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Application\PendingInvitationPurgerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\HttpJson;
use App\Tests\Support\InvitesUsers;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\TestCredentials;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Aucun journal ne nomme un compte par son identifiant de connexion (issue
 * #363). Pour un compte sur invitation, cet identifiant est dérivé de la partie
 * locale de l'adresse (`prenom.sentinelle@…` → `prenom.sentinelle`,
 * UsernameGenerator, ADR 0001 D2) : c'est le plus souvent un prénom et un nom.
 * Un compte se désigne dans les journaux par son `userId` (UUID), que seule la
 * base relie à une personne — et plus rien une fois le compte supprimé.
 *
 * Le parcours traverse la vraie pile et chaque canal qui écrit l'identifiant
 * aujourd'hui ou l'a écrit avant #363 : `security_audit` (sujet et auteur de
 * chaque événement, invitation, activation, rejeu, login raté et réussi, 403
 * du backoffice, déconnexion, actions d'administration, purge), `security`
 * de Symfony (« Authenticator successful! » à chaque requête authentifiée,
 * « Authenticator failed. » pour le JWT d'un compte supprimé), `ai_usage`
 * (refus des deux quotas par compte), les messages des exceptions que le
 * noyau et API Platform journalisent (les 409 du backoffice) et l'avertissement
 * de la purge sur un compte ROLE_SUPER en attente.
 *
 * Toutes les lignes, tous niveaux : la préprod tourne à LOG_LEVEL=debug, et
 * ce qu'elle émet compte. Seules les requêtes SQL de `doctrine` (debug) sont
 * écartées : elles ne sortent que sous `kernel.debug`, jamais en préprod ni
 * en prod (APP_DEBUG=0), comme dans SecurityAuditLogTest. Le mot cherché,
 * `sentinelle`, est dans chaque identifiant et chaque adresse du parcours :
 * une adresse e-mail échouerait aussi, ce qui est voulu.
 *
 * Le test vérifie aussi que le parcours a bien eu lieu (statuts, lignes
 * attendues présentes) : une sonde qui ne voit rien serait verte pour rien.
 */
final class AccountIdentifierReachesNoLogTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use HttpJson;
    use InvitesUsers;
    use ReadsAllChannelsLog;
    use SpeaksMcp;

    private const string SENTINEL = 'sentinelle';
    private const string ADMIN_USERNAME = 'admin.sentinelle';
    private const string INVITEE_EMAIL = 'prenom.sentinelle@example.com';
    private const string PURGED_EMAIL = 'autre.sentinelle@example.com';
    private const string PENDING_SUPER_EMAIL = 'chef.sentinelle@example.com';

    /** Le compte de confiance qui accorde une connexion MCP (#648). */
    private const string MCP_USERNAME = 'mcp.sentinelle';

    /** Doivent refléter rate_limiter.yaml (career_assistant.limit, translation_assistant.limit). */
    private const int ASSISTANT_QUOTA = 30;
    private const int TRANSLATION_QUOTA = 30;

    /** Services concrets derrière les clients scoped des fournisseurs (cf. framework.yaml). */
    private const string SCALEWAY_INNER = 'ai.scaleway.http_client.scoping.inner';
    private const string ANTHROPIC_INNER = 'ai.http_client.scoping.inner';

    /** @var list<string> */
    private array $leaks = [];

    /** @var array<string, int> nombre de lignes vues par « canal/message », pour prouver que la sonde a vu le parcours */
    private array $seen = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        // Les connexions MCP, leurs codes et leurs jetons partent en cascade
        // avec les comptes ; les clients enregistrés, non.
        $connection->executeStatement('DELETE FROM cpg_user');
        $connection->executeStatement('DELETE FROM mcp_client');
        self::forgetMcpSessions($connection);
        parent::tearDown();
    }

    public function testNoLogNamesAnAccountByItsLoginIdentifier(): void
    {
        $client = $this->client();
        $admin = self::getContainer()->get(CpgUserRegistrarInterface::class)->register(self::ADMIN_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->collect('création du compte admin');

        $inviteeId = $this->inviteeActivatedThroughTheBackoffice($client);
        $inviteeJwt = $this->inviteeSession($client, $inviteeId);
        $this->mcpConnectionSession($client);
        $this->adminSession($client, $admin, $inviteeId);
        $this->deletedAccountJwtIsRefused($client, $inviteeJwt);
        $this->pendingInvitationsPurged();

        self::assertSame([], $this->leaks, "Lignes qui nomment un compte par son identifiant :\n".implode("\n", $this->leaks));
        foreach ([
            'security_audit/User invited.',
            'security_audit/Account activated.',
            'security_audit/Password-setup token replayed: link already used.',
            'security_audit/Login failed.',
            'security_audit/Login succeeded.',
            'security_audit/Backoffice access denied.',
            'security_audit/Logged out.',
            'security_audit/Super-admin role changed.',
            'security_audit/Password changed by an administrator.',
            'security_audit/User deleted.',
            'security/Authenticator successful!',
            'security/Authenticator failed.',
            'security_audit/MCP connection granted.',
            'mcp_usage/MCP call',
            'mcp_usage/MCP request without call',
            'ai_usage/Assistant de parcours : quota atteint.',
            'ai_usage/Assistant de traduction : quota atteint.',
        ] as $expected) {
            self::assertArrayHasKey($expected, $this->seen, \sprintf('La sonde n\'a pas vu « %s » : le parcours n\'a pas eu lieu comme prévu.', $expected));
        }
        self::assertGreaterThanOrEqual(3, \count(array_filter(
            array_keys($this->seen),
            static fn (string $key): bool => str_starts_with($key, 'request/Uncaught PHP Exception'),
        )), 'Les trois 409 du backoffice n\'ont pas été journalisés par le noyau.');
    }

    /**
     * Invitation par le backoffice (HTTP, avec l'admin pour auteur), envoi de
     * l'e-mail par le handler, validation du lien, activation, puis rejeu du
     * lien consommé.
     */
    private function inviteeActivatedThroughTheBackoffice(KernelBrowser $client): string
    {
        $csrf = $this->login($client, self::ADMIN_USERNAME, TestCredentials::superPassword());
        $client->request('POST', '/api/backoffice/users', server: $this->writeHeaders($csrf), content: self::jsonBody(['email' => self::INVITEE_EMAIL, 'locale' => 'fr']));
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        $this->collect('invitation');

        $token = $this->runInvitationHandlerAndCollectSetupToken();
        $this->collect('envoi de l\'invitation');
        $client->getCookieJar()->clear();

        foreach (['/api/account/password-setup/validate' => ['token' => $token], '/api/account/password-setup' => ['token' => $token, 'password' => TestCredentials::plainPassword()]] as $path => $payload) {
            $client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($payload));
            self::assertResponseStatusCodeSame(204);
            $this->collect($path);
        }

        $client->request('POST', '/api/account/password-setup/validate', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => $token]));
        self::assertResponseStatusCodeSame(404);
        $this->collect('rejeu du lien');

        return $body['id'];
    }

    /**
     * Le compte sur invitation : un login raté, un login, ses requêtes authentifiées
     * (une permise, une refusée par le backoffice), le quota de l'assistant de
     * parcours, la déconnexion. Renvoie son JWT, rejoué après la suppression.
     */
    private function inviteeSession(KernelBrowser $client, string $inviteeId): string
    {
        $username = $this->usernameOf($inviteeId);

        $this->attemptLogin($client, $username, TestCredentials::variant('erroné'));
        self::assertResponseStatusCodeSame(401);
        $this->collect('login raté de l\'invité');

        $csrf = $this->login($client, $username, TestCredentials::plainPassword());
        $jwt = $client->getCookieJar()->get('BEARER')?->getValue();
        self::assertNotNull($jwt);

        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $this->collect('/api/me');

        $client->request('GET', '/api/backoffice/users');
        self::assertResponseStatusCodeSame(403);
        $this->collect('backoffice refusé à l\'invité');

        $this->exhaust('limiter.career_assistant', $inviteeId, self::ASSISTANT_QUOTA);
        $client->request('POST', '/api/assistant/answers', server: $this->writeHeaders($csrf), content: self::jsonBody(['locale' => 'fr', 'messages' => [
            ['role' => 'user', 'content' => 'Quel est son domaine ?'],
        ]]));
        self::assertResponseStatusCodeSame(429);
        $this->collect('quota de l\'assistant');

        $client->request('POST', '/api/logout', server: ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);
        $this->collect('déconnexion de l\'invité');

        return $jwt;
    }

    /**
     * Une connexion MCP (#648) accordée par un compte de confiance au nom
     * dérivé comme celui d'un invité, par le vrai parcours OAuth : un
     * `tools/call` authentifié par son jeton — le canal `security` écrit
     * « Authenticator successful! » à chaque requête authentifiée, son jeton
     * désignant une connexion MCP, réécrit par SecurityChannelAccountRedactor,
     * qui doit la désigner par l'UUID du compte (`AccountReference`) —, puis
     * le même jeton, révoqué, refusé (« Authenticator failed. »).
     */
    private function mcpConnectionSession(KernelBrowser $client): void
    {
        $client->getCookieJar()->clear();
        $this->client = $client;
        $this->registerOAuthClients();
        $this->collect('enregistrement des clients MCP');

        // Le parcours étape par étape : la sonde est vidée à chaque requête.
        $this->loginAs(self::MCP_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->collect('login du compte MCP');
        $client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($this->validParameters()));
        self::assertResponseStatusCodeSame(302);
        $this->collect('demande d\'autorisation MCP');
        $code = $this->queryOf($this->redirectUriOf($this->decide(approved: true)))['code'] ?? null;
        self::assertIsString($code);
        $this->collect('consentement MCP');
        $held = $this->heldMcpConnectionOf($this->exchange($code));
        $this->collect('échange du code MCP');

        $sessionId = $this->initializeMcpSession($client, self::bearer($held->accessToken));
        $this->collect('initialize MCP authentifié');

        $this->mcpCall($client, $sessionId, 'tools/call', ['name' => 'get_contact_info', 'arguments' => new stdClass()], self::bearer($held->accessToken));
        self::assertResponseIsSuccessful();
        $this->assertTheSecurityChannelDesignatesTheAccount($held->accountId);
        $this->collect('tools/call MCP authentifié');

        $this->alterHeldAccessToken($held, 'revoked_at = now()');
        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], self::bearer($held->accessToken));
        self::assertResponseStatusCodeSame(401);
        $this->collect('jeton MCP révoqué');
    }

    /**
     * « Authenticator successful! » de la requête MCP désigne la connexion par
     * le compte, sous son UUID (`userId`) — jamais muet, jamais l'identifiant
     * de connexion.
     */
    private function assertTheSecurityChannelDesignatesTheAccount(string $accountId): void
    {
        $successes = array_values(array_filter(
            self::allChannelsLogRecordsOf('security'),
            static fn (LogRecord $record): bool => 'Authenticator successful!' === $record->message,
        ));
        self::assertCount(1, $successes);
        // Le processeur du canal a déjà réécrit le jeton en chaîne quand le handler le reçoit.
        self::assertSame(\sprintf('PostAuthenticationToken(userId="%s", roles="ROLE_TRUSTED, ROLE_USER")', $accountId), $successes[0]->context['token'] ?? null);
    }

    /**
     * L'admin : les trois 409 atteignables du backoffice (relance d'un compte
     * actif, suppression de soi, ses propres rôles), le quota du traducteur,
     * puis promotion, rétrogradation, mot de passe et suppression de l'invité.
     */
    private function adminSession(KernelBrowser $client, CpgUser $admin, string $inviteeId): void
    {
        $client->getCookieJar()->clear();
        $csrf = $this->login($client, self::ADMIN_USERNAME, TestCredentials::superPassword());
        $adminId = $admin->getId()->toRfc4122();

        foreach ([
            ['POST', "/api/backoffice/users/{$inviteeId}/invitation", ['locale' => 'fr']],
            ['DELETE', "/api/backoffice/users/{$adminId}", null],
            ['PUT', "/api/backoffice/users/{$adminId}/roles", ['superAdmin' => false]],
        ] as [$method, $path, $payload]) {
            $client->request($method, $path, server: $this->writeHeaders($csrf), content: null === $payload ? null : self::jsonBody($payload));
            self::assertResponseStatusCodeSame(409, $method.' '.$path);
            $this->collect('409 '.$method.' '.$path);
        }

        $this->exhaust('limiter.translation_assistant', $adminId, self::TRANSLATION_QUOTA);
        $client->request('POST', '/api/backoffice/translations', server: $this->writeHeaders($csrf), content: self::jsonBody([
            'sourceLocale' => 'fr',
            'targetLocale' => 'en',
            'fields' => ['title' => 'Panne du broker RabbitMQ'],
        ]));
        self::assertResponseStatusCodeSame(429);
        $this->collect('quota du traducteur');

        foreach ([
            ['PUT', "/api/backoffice/users/{$inviteeId}/roles", ['superAdmin' => true], 204],
            ['PUT', "/api/backoffice/users/{$inviteeId}/roles", ['superAdmin' => false], 204],
            ['PUT', "/api/backoffice/users/{$inviteeId}/password", ['password' => TestCredentials::variant('posé par l\'admin')], 204],
            ['DELETE', "/api/backoffice/users/{$inviteeId}", null, 204],
        ] as [$method, $path, $payload, $status]) {
            $client->request($method, $path, server: $this->writeHeaders($csrf), content: null === $payload ? null : self::jsonBody($payload));
            self::assertResponseStatusCodeSame($status, $method.' '.$path);
            $this->collect($method.' '.$path);
        }
    }

    /** Le JWT d'un compte supprimé reste valide jusqu'à son expiration : le firewall `api` ne trouve plus son compte. */
    private function deletedAccountJwtIsRefused(KernelBrowser $client, string $jwt): void
    {
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie('BEARER', $jwt));
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        $this->collect('JWT d\'un compte supprimé');
    }

    /** Une invitation expirée purgée, un ROLE_SUPER en attente ignoré (avertissement). */
    private function pendingInvitationsPurged(): void
    {
        $inviter = self::getContainer()->get(CpgUserInviterInterface::class);
        $inviter->invite(self::PURGED_EMAIL, Locale::FR);
        $pendingSuper = $inviter->invite(self::PENDING_SUPER_EMAIL, Locale::FR);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("UPDATE cpg_user SET invited_at = invited_at - INTERVAL '40 days' WHERE password = ''");
        $connection->executeStatement('UPDATE cpg_user SET roles = :roles WHERE id = :id', [
            'roles' => json_encode([CpgUser::ROLE_SUPER], \JSON_THROW_ON_ERROR),
            'id' => $pendingSuper->getId()->toRfc4122(),
        ]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->collect('invitations à purger');

        $result = self::getContainer()->get(PendingInvitationPurgerInterface::class)->purge(RetentionPeriod::fromString('30 days', new DateTimeImmutable()));
        self::assertCount(1, $result->purged);
        self::assertCount(1, $result->skipped);
        $this->collect('purge');
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        // Un seul kernel pour tout le parcours : les clients HTTP simulés des
        // fournisseurs restent en place (aucun appel ne part) et la sonde de
        // tous les canaux est relue après chaque étape.
        $client->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::getContainer()->set(self::SCALEWAY_INNER, new MockHttpClient(new MockResponse('', ['http_code' => 503])));
        self::getContainer()->set(self::ANTHROPIC_INNER, new MockHttpClient(new MockResponse('', ['http_code' => 503])));

        return $client;
    }

    private function exhaust(string $limiter, string $key, int $quota): void
    {
        $factory = self::getContainer()->get($limiter);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        self::assertTrue($factory->create($key)->consume($quota)->isAccepted(), 'Le quota était déjà entamé.');
    }

    private function usernameOf(string $id): string
    {
        $username = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT username FROM cpg_user WHERE id = :id', ['id' => $id]);
        self::assertIsString($username);
        self::assertStringContainsString(self::SENTINEL, $username, 'L\'identifiant n\'est plus dérivé de l\'adresse : le test ne garde plus rien.');

        return $username;
    }

    private function attemptLogin(KernelBrowser $client, string $username, string $password): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
    }

    private function login(KernelBrowser $client, string $username, string $password): string
    {
        $this->attemptLogin($client, $username, $password);
        self::assertResponseIsSuccessful();
        $this->collect('login de '.$username);
        $csrf = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrf);

        return $csrf->getValue();
    }

    /** @return array<string, string> */
    private function writeHeaders(string $csrf): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf];
    }

    /**
     * Relit la sonde de tous les canaux après une étape, puis la vide : le
     * kernel n'étant pas reconstruit, elle cumulerait sinon tout le parcours,
     * et le service resetter la vide de toute façon entre deux requêtes.
     */
    private function collect(string $step): void
    {
        $formatter = new JsonFormatter();

        foreach (self::allChannelsLogRecords() as $record) {
            if ('doctrine' === $record->channel && Level::Debug === $record->level) {
                continue;
            }

            $key = $record->channel.'/'.$record->message;
            $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;

            $line = $formatter->format($record);
            if (false !== stripos($line, self::SENTINEL)) {
                $this->leaks[] = \sprintf('[%s] %s/%s « %s » : %s', $step, $record->channel, $record->level->getName(), $record->message, mb_substr(trim($line), 0, 300));
            }
        }

        $this->clearProbe();
    }

    private function clearProbe(): void
    {
        foreach (self::getContainer()->get('monolog.logger.security_audit')->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                $handler->clear();
            }
        }
    }
}
