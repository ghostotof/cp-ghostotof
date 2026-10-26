<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Security;

use App\Mcp\Connection\Domain\ValueObject\BearerRefusal;
use App\Mcp\Connection\Infrastructure\Security\McpBearerRefusedException;
use App\Mcp\Connection\Infrastructure\Security\McpConnectionUser;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'authentificateur Bearer du firewall `mcp` (#648, ADR 0006 D4, spec 0007
 * « Authentificateur ») : un client MCP présente `Authorization: Bearer
 * <jeton opaque>` sur `/api/mcp`, et le jeton est **relu en base à chaque
 * requête** avec sa connexion, son compte et ses rôles — une connexion
 * révoquée ou expirée, un compte qui perd le palier de confiance cessent
 * d'ouvrir quoi que ce soit à la requête suivante.
 *
 * Le jeton vient du vrai parcours OAuth (HoldsAnMcpConnection) ; les
 * altérations se font en SQL entre deux requêtes, le kernel redémarrant
 * entre elles (un EntityManager neuf à chaque requête, comme en production).
 * L'appel témoin est `tools/call get_contact_info`, un outil public qui ne
 * lit pas la base : ce qu'un test compte en SQL est l'authentification et la
 * session MCP, rien d'autre.
 *
 * Chaque refus est vérifié sous ses deux faces : la réponse (401 sans corps,
 * le défi `error="invalid_token"`, `no-store`, aucun jeton dans le
 * `token_storage`) et sa cause, lue dans la ligne « Authenticator failed. »
 * du canal `security` — avec, pour un jeton connu, la ligne d'audit
 * `mcp-access-token-refused` (#649), la seule chose qui distingue un jeton
 * révoqué d'un jeton inconnu, le client recevant la même réponse.
 */
final class McpBearerAuthenticatorTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsAllChannelsLog;
    use ReadsSecurityAuditLog;
    use SpeaksMcp;

    /** Un jeton émis par league : 40 octets aléatoires… */
    private const int TOKEN_BYTES = 40;

    /** …écrits en 80 hexadécimaux minuscules. */
    private const int TOKEN_LENGTH = 2 * self::TOKEN_BYTES;

    /** L'événement d'audit du refus d'un jeton connu (#649). */
    private const string ACCESS_TOKEN_REFUSED = 'mcp-access-token-refused';

    /** La ligne altérée par un cas de refus : le jeton d'accès… */
    private const string ACCESS_TOKEN_ROW = 'access-token';

    /** …sa connexion… */
    private const string CONNECTION_ROW = 'connection';

    /** …ou le compte, dont les rôles stockés sont vidés (le palier de base). */
    private const string ACCOUNT_ROW = 'account';

    /** La session ouverte par heldAndServedOnce(), où la requête suivante est refusée. */
    private ?string $heldSessionId = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->setUpOAuthFlow();
        // La première requête ne redémarre pas le kernel de createClient() :
        // la sonde garderait sinon les écritures de la préparation.
        $this->clearLogProbe();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testAValidTokenAuthenticatesTheConnection(): void
    {
        $held = $this->holdMcpConnection();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $this->callContactInfo($sessionId, self::bearer($held->accessToken));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->client->getResponse()->headers->has('WWW-Authenticate'));
        $user = $this->authenticatedUser();
        self::assertSame($held->accountId, $user->accountId());
        self::assertSame($held->connectionId, $user->connectionId()->toRfc4122());
        self::assertSame($held->clientId, $user->clientId());
        self::assertSame(self::CLIENT_ID, $user->clientId());
        // L'identifiant de l'utilisateur du firewall est la connexion, jamais
        // l'identifiant de connexion du compte (#363).
        self::assertSame($held->connectionId, $user->getUserIdentifier());
        self::assertContains(CpgUser::ROLE_TRUSTED, $user->getRoles());
    }

    /** RFC 7235 §2.1 : le schéma d'authentification est insensible à la casse. */
    #[DataProvider('schemeSpellings')]
    public function testTheSchemeIsCaseInsensitive(string $scheme): void
    {
        $held = $this->holdMcpConnection();

        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], ['HTTP_AUTHORIZATION' => $scheme.' '.$held->accessToken]);

        self::assertResponseIsSuccessful();
        self::assertSame($held->connectionId, $this->authenticatedUser()->getUserIdentifier());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemeSpellings(): iterable
    {
        yield 'minuscules' => ['bearer'];
        yield 'majuscules' => ['BEARER'];
    }

    public function testARevokedConnectionIsRefusedAtTheNextRequest(): void
    {
        $held = $this->heldAndServedOnce();

        $this->alterHeldConnection($held, "revoked_at = now(), revocation_cause = 'client'");

        $this->assertNextCallRefusedBecause($held, BearerRefusal::ConnectionRevoked);
    }

    /**
     * ADR 0006 D4 : la perte du palier prend effet à la requête suivante — le
     * compte est relu avec le jeton, jamais gardé d'une requête à l'autre.
     */
    public function testAnAccountThatLostTrustIsRefusedAtTheNextRequest(): void
    {
        $held = $this->heldAndServedOnce();

        $this->rewriteHeldAccountRoles($held, []);

        $this->assertNextCallRefusedBecause($held, BearerRefusal::AccountNotTrusted);
    }

    /** 90 jours après l'octroi, la connexion expire quel que soit son usage (ConnectionLifetimes::maximum(), en configuration). */
    public function testAnExpiredConnectionIsRefusedAtTheNextRequest(): void
    {
        $held = $this->heldAndServedOnce();

        $this->alterHeldConnection($held, "granted_at = granted_at - INTERVAL '91 days'");

        $this->assertNextCallRefusedBecause($held, BearerRefusal::ConnectionExpired);
    }

    /**
     * Le scope stocké sur la connexion est relu à chaque requête : réécrit en
     * base sans `nominative:read`, la connexion n'ouvre plus rien, et le
     * client reçoit le même 401 `invalid_token` que pour toute autre cause.
     */
    public function testAConnectionWithoutTheNominativeReadScopeIsRefused(): void
    {
        $held = $this->heldAndServedOnce();

        $this->alterHeldConnection($held, "scope = 'profile'");

        $this->assertNextCallRefusedBecause($held, BearerRefusal::InsufficientScope);
    }

    /**
     * Le report de la tâche 8 : league ne regarde pas l'échéance d'un jeton
     * opaque, c'est l'authentificateur qui la juge.
     */
    public function testAnExpiredTokenIsRefused(): void
    {
        $held = $this->heldAndServedOnce();

        $this->alterHeldAccessToken($held, "expires_at = now() - INTERVAL '1 second'");

        $this->assertNextCallRefusedBecause($held, BearerRefusal::TokenExpired);
    }

    public function testARevokedTokenIsRefused(): void
    {
        $held = $this->heldAndServedOnce();

        $this->alterHeldAccessToken($held, 'revoked_at = now()');

        $this->assertNextCallRefusedBecause($held, BearerRefusal::TokenRevoked);
    }

    /** RFC 8707 : l'audience se compare octet pour octet, une barre finale de plus est une autre ressource. */
    public function testATokenOfAnotherAudienceIsRefused(): void
    {
        $held = $this->heldAndServedOnce();

        $this->alterHeldAccessToken($held, "audience = audience || '/'");

        $this->assertNextCallRefusedBecause($held, BearerRefusal::ForeignAudience);
    }

    /**
     * Review Focus 3 : ROLE_SUPER sans ROLE_TRUSTED stocké atteint le palier
     * de confiance par la `role_hierarchy`, comme partout ailleurs sur le site.
     */
    public function testASuperAdminWithoutStoredTrustIsAccepted(): void
    {
        $held = $this->holdMcpConnection();
        $this->rewriteHeldAccountRoles($held, [CpgUser::ROLE_SUPER]);
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $this->callContactInfo($sessionId, self::bearer($held->accessToken));

        self::assertResponseIsSuccessful();
        self::assertNotContains(CpgUser::ROLE_TRUSTED, $this->authenticatedUser()->getRoles(), 'Les rôles du jeton sont les rôles stockés : la hiérarchie se joue au vote.');
    }

    /** Review Focus 3 : le palier de base (`ROLE_USER` seul) n'ouvre rien. */
    public function testABaseTierAccountIsRefused(): void
    {
        $held = $this->holdMcpConnection();
        $this->rewriteHeldAccountRoles($held, []);

        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], self::bearer($held->accessToken));

        $this->assertRefusedBecause(BearerRefusal::AccountNotTrusted);
    }

    /**
     * Review Focus 1 : derrière le Basic Auth de l'Ingress de préprod, chaque
     * requête porte `Authorization: Basic …`. Ce n'est pas un jeton présenté :
     * la requête reste anonyme, jamais un 401 qui ferait chercher au client
     * une connexion MCP.
     */
    public function testABasicAuthorizationHeaderLeavesTheRequestAnonymous(): void
    {
        $basic = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('preprod:'.bin2hex(random_bytes(8)))];
        $sessionId = $this->initializeMcpSession($this->client, $basic);

        $this->callContactInfo($sessionId, $basic);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->client->getResponse()->headers->has('WWW-Authenticate'));
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());
    }

    /**
     * Le schéma est `Bearer` suivi d'une espace, ou seul : collé au jeton, ou
     * suivi d'une tabulation, ce n'est plus ce schéma, et la requête reste
     * anonyme — même quand ce qui suit est un jeton valide, qui n'est alors
     * pas lu. Ce n'est pas un 401 : un client qui ne présente pas de Bearer
     * n'a rien à rafraîchir.
     *
     * @return iterable<string, array{string}>
     */
    public static function otherSchemes(): iterable
    {
        yield 'tabulation' => ["Bearer\t%s"];
        yield 'collé au jeton' => ['Bearer%s'];
        yield 'un autre mot' => ['Bearerxyz'];
    }

    #[DataProvider('otherSchemes')]
    public function testAnotherSchemeLeavesTheRequestAnonymous(string $authorization): void
    {
        $held = $this->holdMcpConnection();
        $server = ['HTTP_AUTHORIZATION' => \sprintf($authorization, $held->accessToken)];
        $sessionId = $this->initializeMcpSession($this->client, $server);

        $this->callContactInfo($sessionId, $server);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->client->getResponse()->headers->has('WWW-Authenticate'));
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());
    }

    /**
     * Review Focus 2 : un Bearer qui n'a pas la forme d'un jeton émis (80
     * hexadécimaux minuscules) est refusé avant toute requête — ni empreinte
     * calculée sur 10 Kio, ni aller-retour vers la base pour une chaîne qui ne
     * peut rien désigner. Témoin positif : un jeton bien formé mais inconnu
     * coûte bien sa requête sur `mcp_access_token`, donc le journal SQL lu
     * n'est pas muet.
     */
    #[DataProvider('malformedBearers')]
    public function testAMalformedBearerIsRefusedWithoutAnyQuery(string $authorization): void
    {
        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], ['HTTP_AUTHORIZATION' => $authorization]);

        $this->assertRefusedBecause(BearerRefusal::Malformed);
        self::assertSame([], $this->executedSql(), 'Un Bearer mal formé ne doit coûter aucune requête.');

        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], self::bearer($this->unknownToken()));

        $this->assertRefusedBecause(BearerRefusal::Unknown);
        $queries = $this->executedSql();
        self::assertCount(1, $queries, implode("\n", $queries));
        self::assertStringContainsString('FROM mcp_access_token', $queries[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBearers(): iterable
    {
        // 10 Kio d'octets aléatoires, 20 Kio d'hexadécimaux : de quoi tailler chaque cas.
        $hex = static fn (int $length): string => substr(bin2hex(random_bytes(10 * 1024)), 0, $length);

        yield 'schéma seul' => ['Bearer'];
        yield 'schéma et espace' => ['Bearer '];
        yield '79 hexadécimaux' => ['Bearer '.$hex(self::TOKEN_LENGTH - 1)];
        yield '81 hexadécimaux' => ['Bearer '.$hex(self::TOKEN_LENGTH + 1)];
        yield 'majuscules' => ['Bearer '.strtoupper($hex(self::TOKEN_LENGTH - 1)).'A'];
        yield 'non hexadécimal' => ['Bearer '.$hex(self::TOKEN_LENGTH - 1).'g'];
        yield 'deux espaces' => ['Bearer  '.$hex(self::TOKEN_LENGTH)];
        yield 'fin de ligne finale' => ['Bearer '.$hex(self::TOKEN_LENGTH)."\n"];
        yield '10 Kio' => ['Bearer '.$hex(10 * 1024)];
    }

    /**
     * Décision 2 de l'auteur (2026-10-24) : un jeton présenté et refusé est un
     * 401 `invalid_token` sur **toute** requête de `/api/mcp` — l'`initialize`,
     * un outil public, l'outil nominatif, dans une session comme sous
     * l'enveloppe sans session de 2026-07-28, marqueur `openai/` ou non (il
     * ne choisit que la forme du refus d'un appel anonyme, jamais l'accès).
     * C'est ce qui fait rafraîchir le client (RFC 6750 §3.1).
     */
    public function testARefusedTokenIs401OnEveryRequest(): void
    {
        $sessionId = $this->initializeMcpSession($this->client);
        $refused = self::bearer($this->unknownToken());
        $openAiMeta = ['openai/subject' => 'subject-1'];

        $requests = [
            'initialize' => fn () => $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], $refused),
            'tools/list' => fn () => $this->mcpRequest($sessionId, 'tools/list', [], $refused),
            'tools/call get_about' => fn () => $this->mcpRequest($sessionId, 'tools/call', ['name' => 'get_about', 'arguments' => ['locale' => 'en']], $refused),
            'tools/call get_nominative_corpus' => fn () => $this->mcpRequest($sessionId, 'tools/call', ['name' => 'get_nominative_corpus', 'arguments' => ['locale' => 'en']], $refused),
            '2026-07-28 tools/call get_about' => fn () => $this->postModernMcp($this->client, 'tools/call', ['name' => 'get_about', 'arguments' => ['locale' => 'en']], ['name' => 'phpunit', 'version' => '1.0.0'], $refused),
            '2026-07-28 tools/call get_about, openai/' => fn () => $this->postModernMcp($this->client, 'tools/call', ['name' => 'get_about', 'arguments' => ['locale' => 'en']], ['name' => 'phpunit', 'version' => '1.0.0'], $refused, meta: $openAiMeta),
            '2026-07-28 tools/call get_nominative_corpus, openai/' => fn () => $this->postModernMcp($this->client, 'tools/call', ['name' => 'get_nominative_corpus', 'arguments' => ['locale' => 'en']], ['name' => 'phpunit', 'version' => '1.0.0'], $refused, meta: $openAiMeta),
            'GET' => fn (): Crawler => $this->client->request('GET', self::MCP_ENDPOINT, server: $refused + ['HTTP_ACCEPT' => 'text/event-stream']),
        ];

        foreach ($requests as $name => $send) {
            $send();
            $this->assertRefusedBecause(BearerRefusal::Unknown, $name);
        }
    }

    /**
     * Rien en clair (#648) : ni le jeton ni son empreinte n'atteignent un
     * journal, d'aucun canal, à aucun niveau — ni sur un appel authentifié
     * (« Authenticator successful! » du canal `security`, la ligne d'appel
     * `mcp_usage`), ni sur un refus (« Authenticator failed. », la ligne de
     * repli). Seules les requêtes SQL de `doctrine` (`debug`) sont écartées :
     * elles citent l'empreinte en paramètre, mais ne sortent que sous
     * `kernel.debug`, jamais en préprod ni en prod (APP_DEBUG=0). Témoin
     * positif : les lignes attendues de chaque requête ont bien été vues.
     */
    public function testNeitherTheTokenNorItsHashReachesAnyLog(): void
    {
        $held = $this->holdMcpConnection();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));
        $secrets = [$held->accessToken, hash('sha256', $held->accessToken)];

        $this->callContactInfo($sessionId, self::bearer($held->accessToken));
        self::assertResponseIsSuccessful();
        $this->assertNoLogQuotes($secrets, ['security/Authenticator successful!', 'mcp_usage/MCP call']);

        $this->alterHeldAccessToken($held, 'revoked_at = now()');
        $this->callContactInfo($sessionId, self::bearer($held->accessToken));
        $this->assertRefusedBecause(BearerRefusal::TokenRevoked);
        $this->assertNoLogQuotes($secrets, ['security/Authenticator failed.', 'mcp_usage/MCP request without call']);
    }

    /**
     * ADR 0006 D4 : aucune écriture par appel hors de la session MCP — ni
     * `last_used_at` (écrit au rafraîchissement, #649), ni rien d'autre.
     * Témoin positif : l'écriture de la session elle-même est vue.
     */
    public function testNoRequestWritesOutsideTheMcpSessions(): void
    {
        $held = $this->holdMcpConnection();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $this->callContactInfo($sessionId, self::bearer($held->accessToken));

        self::assertResponseIsSuccessful();
        $writes = array_values(array_filter($this->executedSql(), static fn (string $sql): bool => 1 === preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql)));
        self::assertNotSame([], array_filter($writes, static fn (string $sql): bool => str_contains($sql, 'cache_items')), 'La session MCP n\'a pas été écrite : le journal SQL lu est muet.');
        self::assertSame([], array_values(array_filter($writes, static fn (string $sql): bool => !str_contains($sql, 'cache_items'))));
        self::assertNull($this->mcpRows()->fetchOne('SELECT last_used_at FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]));
    }

    /**
     * ADR 0006 D4 : l'authentification coûte une requête SQL, jointure du
     * jeton, de la connexion, du compte et du client ; un appel sans jeton
     * n'en paie aucune. Le même appel dans la même session, anonyme puis
     * authentifié : exactement une requête de plus, celle du jeton.
     */
    public function testAnAuthenticatedCallCostsExactlyOneQueryMore(): void
    {
        $held = $this->holdMcpConnection();
        $sessionId = $this->initializeMcpSession($this->client);

        $this->callContactInfo($sessionId);
        self::assertResponseIsSuccessful();
        $anonymous = $this->executedSql();

        $this->callContactInfo($sessionId, self::bearer($held->accessToken));
        self::assertResponseIsSuccessful();
        $authenticated = $this->executedSql();

        self::assertCount(\count($anonymous) + 1, $authenticated, implode("\n", $authenticated));
        self::assertCount(1, array_filter($authenticated, static fn (string $sql): bool => str_contains($sql, 'mcp_access_token')));
        self::assertSame([], array_filter($anonymous, static fn (string $sql): bool => str_contains($sql, 'mcp_') || str_contains($sql, 'cpg_user')));
    }

    /**
     * Les refus d'un jeton connu (#649) : la ligne, la cause et la table
     * altérée, avec les mêmes altérations SQL que les tests de refus
     * ci-dessus.
     *
     * @return iterable<string, array{self::ACCESS_TOKEN_ROW|self::CONNECTION_ROW|self::ACCOUNT_ROW, string, BearerRefusal}>
     */
    public static function knownRefusals(): iterable
    {
        yield 'jeton révoqué' => [self::ACCESS_TOKEN_ROW, 'revoked_at = now()', BearerRefusal::TokenRevoked];
        yield 'jeton échu' => [self::ACCESS_TOKEN_ROW, "expires_at = now() - INTERVAL '1 second'", BearerRefusal::TokenExpired];
        yield 'audience étrangère' => [self::ACCESS_TOKEN_ROW, "audience = audience || '/'", BearerRefusal::ForeignAudience];
        yield 'connexion révoquée' => [self::CONNECTION_ROW, "revoked_at = now(), revocation_cause = 'client'", BearerRefusal::ConnectionRevoked];
        yield 'connexion expirée' => [self::CONNECTION_ROW, "granted_at = granted_at - INTERVAL '91 days'", BearerRefusal::ConnectionExpired];
        yield 'scope' => [self::CONNECTION_ROW, "scope = 'profile'", BearerRefusal::InsufficientScope];
        yield 'compte hors palier' => [self::ACCOUNT_ROW, '', BearerRefusal::AccountNotTrusted];
    }

    /**
     * Le refus d'un jeton connu est audité (#649) : une ligne
     * `mcp-access-token-refused` désignant le compte par son UUID, la
     * connexion, le client par son identifiant public et la cause — le mot de
     * BearerRefusal. Jamais le jeton ni son empreinte (#363, #648). L'auteur
     * est `anonymous` : le refus a lieu avant toute authentification. La
     * réponse reste le 401 commun, quelle que soit la cause.
     *
     * @param self::ACCESS_TOKEN_ROW|self::CONNECTION_ROW|self::ACCOUNT_ROW $row
     */
    #[DataProvider('knownRefusals')]
    public function testAKnownRefusedTokenIsAudited(string $row, string $assignments, BearerRefusal $cause): void
    {
        $held = $this->heldAndServedOnce();
        self::assertSame([], self::securityAuditEvents(self::ACCESS_TOKEN_REFUSED), 'Un appel accepté n\'écrit aucun refus.');

        $this->alterHeldRow($held, $row, $assignments);
        $this->assertNextCallRefusedBecause($held, $cause);

        $line = self::singleSecurityAuditEvent(self::ACCESS_TOKEN_REFUSED);
        self::assertSame($held->accountId, $line['userId']);
        self::assertSame($held->connectionId, $line['connectionId']);
        self::assertSame($held->clientId, $line['clientId']);
        self::assertSame($cause->value, $line['cause']);
        self::assertSame('anonymous', $line['actor']);
        self::assertSame(self::MCP_ENDPOINT, $line['path']);
        $serialized = json_encode($line, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($held->accessToken, $serialized);
        self::assertStringNotContainsString(hash('sha256', $held->accessToken), $serialized);
    }

    /**
     * Ni un Bearer mal formé ni un jeton inconnu ne sont audités (#649) :
     * aucune connexion n'y est connue, et un déluge de jetons fantaisistes ne
     * doit pas écrire de lignes d'audit. Témoin : chaque refus a bien eu lieu.
     */
    public function testAMalformedOrUnknownTokenIsNotAudited(): void
    {
        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], ['HTTP_AUTHORIZATION' => 'Bearer not-a-token']);
        $this->assertRefusedBecause(BearerRefusal::Malformed);
        self::assertSame([], self::securityAuditRecords(), 'Un Bearer mal formé n\'écrit rien dans l\'audit.');

        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], self::bearer($this->unknownToken()));
        $this->assertRefusedBecause(BearerRefusal::Unknown);
        self::assertSame([], self::securityAuditRecords(), 'Un jeton inconnu n\'écrit rien dans l\'audit.');
    }

    /**
     * Un refus jugé sur le jeton et sa connexion, un autre jugé sur le palier
     * du compte, le dernier contrôle de l'authentificateur.
     *
     * @return iterable<string, array{self::ACCESS_TOKEN_ROW|self::CONNECTION_ROW|self::ACCOUNT_ROW, string, BearerRefusal}>
     */
    public static function refusalsAtEachStage(): iterable
    {
        yield 'jugé par le jeton' => [self::ACCESS_TOKEN_ROW, 'revoked_at = now()', BearerRefusal::TokenRevoked];
        yield 'jugé par le palier' => [self::ACCOUNT_ROW, '', BearerRefusal::AccountNotTrusted];
    }

    /**
     * L'audit du refus ne coûte aucune requête (#649) : il part sur le canal
     * Monolog `security_audit`, jamais en base. Un jeton connu refusé coûte la
     * requête de l'authentification, la même que celle de l'appel accepté, et
     * rien d'autre — ni compte, ni client, ni connexion relus pour la ligne
     * d'audit : la requête du jeton les a joints. Le refus précède la session
     * MCP, qui n'est pas lue.
     *
     * @param self::ACCESS_TOKEN_ROW|self::CONNECTION_ROW|self::ACCOUNT_ROW $row
     */
    #[DataProvider('refusalsAtEachStage')]
    public function testARefusedKnownTokenCostsNoExtraQuery(string $row, string $assignments, BearerRefusal $cause): void
    {
        $held = $this->heldAndServedOnce();
        $accepted = array_values(array_filter($this->executedSql(), static fn (string $sql): bool => str_contains($sql, 'mcp_') || str_contains($sql, 'cpg_user')));
        self::assertCount(1, $accepted, implode("\n", $accepted));

        $this->alterHeldRow($held, $row, $assignments);
        $this->assertNextCallRefusedBecause($held, $cause);
        self::assertCount(1, self::securityAuditEvents(self::ACCESS_TOKEN_REFUSED), 'Témoin : le refus a bien été audité.');

        self::assertSame($accepted, $this->executedSql());
    }

    /**
     * Le firewall `mcp` ne lit jamais de cookie (ADR 0006 D4) : un `BEARER`
     * de confiance sans en-tête `Authorization` laisse la requête anonyme, et
     * rien n'est lu en base pour lui.
     */
    public function testATrustedBearerCookieWithoutAuthorizationStaysAnonymous(): void
    {
        $this->loginAs('mcp.cookie', [CpgUser::ROLE_TRUSTED]);
        self::assertNotNull($this->client->getCookieJar()->get('BEARER'));

        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0']);

        self::assertResponseIsSuccessful();
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());
        self::assertSame([], array_filter($this->executedSql(), static fn (string $sql): bool => str_contains($sql, 'mcp_access_token') || str_contains($sql, 'cpg_user')));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function openAiMarking(): iterable
    {
        yield 'non marqué' => [false];
        yield 'marqué openai/' => [true];
    }

    /**
     * Le même cookie de confiance, sur l'appel qui compte : l'outil nominatif
     * (relecture de sécurité de #648). Ni la garde CSRF (un 403) ne refuse
     * la requête, ni le cookie n'ouvre le corpus — l'appel est anonyme : non
     * marqué, le 401 différé, sans `error=` puisqu'aucun jeton n'a été
     * présenté ; marqué `openai/`, l'erreur d'outil.
     */
    #[DataProvider('openAiMarking')]
    public function testATrustedBearerCookieNeverOpensTheNominativeCorpus(bool $marked): void
    {
        $this->loginAs('mcp.cookie', [CpgUser::ROLE_TRUSTED]);
        self::assertNotNull($this->client->getCookieJar()->get('BEARER'));
        $sessionId = $this->initializeMcpSession($this->client);

        $params = ['name' => 'get_nominative_corpus', 'arguments' => ['locale' => 'en']];
        if ($marked) {
            $params['_meta'] = ['openai/subject' => 'subject-1'];
        }
        $this->mcpRequest($sessionId, 'tools/call', $params, []);

        $response = $this->client->getResponse();
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());
        self::assertStringNotContainsString('structuredContent', (string) $response->getContent());
        self::assertSame([], self::securityAuditEvents('nominative-corpus-read'), 'Un cookie n\'ouvre aucune lecture.');
        if (!$marked) {
            self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
            self::assertSame(
                'Bearer resource_metadata="'.$this->publicApiBase().'/.well-known/oauth-protected-resource/api/mcp", scope="nominative:read"',
                $response->headers->get('WWW-Authenticate'),
            );

            return;
        }

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $result = $this->decodeMcpResponse($this->client)['result'] ?? null;
        self::assertIsArray($result);
        self::assertTrue($result['isError']);
        self::assertArrayHasKey('mcp/www_authenticate', $result['_meta'] ?? []);
    }

    /** Une connexion, une session ouverte avec son jeton, un premier appel servi. */
    private function heldAndServedOnce(): HeldMcpConnection
    {
        $held = $this->holdMcpConnection();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));
        $this->callContactInfo($sessionId, self::bearer($held->accessToken));
        self::assertResponseIsSuccessful();
        $this->authenticatedUser();
        $this->heldSessionId = $sessionId;

        return $held;
    }

    /**
     * Altère la ligne nommée par un cas de refus, par le même SQL que les tests de refus.
     *
     * @param self::ACCESS_TOKEN_ROW|self::CONNECTION_ROW|self::ACCOUNT_ROW $row
     */
    private function alterHeldRow(HeldMcpConnection $held, string $row, string $assignments): void
    {
        match ($row) {
            self::ACCESS_TOKEN_ROW => $this->alterHeldAccessToken($held, $assignments),
            self::CONNECTION_ROW => $this->alterHeldConnection($held, $assignments),
            self::ACCOUNT_ROW => $this->rewriteHeldAccountRoles($held, []),
        };
    }

    private function assertNextCallRefusedBecause(HeldMcpConnection $held, BearerRefusal $cause): void
    {
        self::assertNotNull($this->heldSessionId);
        $this->callContactInfo($this->heldSessionId, self::bearer($held->accessToken));

        $this->assertRefusedBecause($cause);
    }

    /**
     * Le refus d'un jeton présenté : 401 sans corps, le défi `invalid_token`
     * (RFC 6750 §3.1) portant l'URL des métadonnées et le scope, jamais mis
     * en cache, aucune identité retenue — et la cause, nommée côté serveur
     * seulement.
     */
    private function assertRefusedBecause(BearerRefusal $cause, string $request = ''): void
    {
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), $request.' '.substr((string) $response->getContent(), 0, 300));
        self::assertSame('', (string) $response->getContent(), $request);
        self::assertSame(
            'Bearer resource_metadata="'.$this->publicApiBase().'/.well-known/oauth-protected-resource/api/mcp", scope="nominative:read", error="invalid_token"',
            $response->headers->get('WWW-Authenticate'),
            $request,
        );
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $request);
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken(), $request);

        $failures = array_values(array_filter(
            self::allChannelsLogRecordsOf('security'),
            static fn (LogRecord $record): bool => 'Authenticator failed.' === $record->message,
        ));
        self::assertCount(1, $failures, $request);
        $exception = $failures[0]->context['exception'] ?? null;
        self::assertInstanceOf(McpBearerRefusedException::class, $exception, $request);
        self::assertSame('MCP access token refused: '.$cause->value, $exception->getMessage(), $request);
    }

    private function authenticatedUser(): McpConnectionUser
    {
        $user = self::getContainer()->get('security.token_storage')->getToken()?->getUser();
        self::assertInstanceOf(McpConnectionUser::class, $user);

        return $user;
    }

    /**
     * `tools/call get_contact_info` dans la session : un outil public, qui ne
     * lit pas la base.
     *
     * @param array<string, string> $server
     */
    private function callContactInfo(string $sessionId, array $server = []): void
    {
        $this->mcpRequest($sessionId, 'tools/call', ['name' => 'get_contact_info', 'arguments' => ['locale' => 'en']], $server);
    }

    /**
     * Une requête dans la session, la réponse laissée au test : un 401 n'a
     * pas de corps JSON à décoder.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $server
     */
    private function mcpRequest(string $sessionId, string $method, array $params, array $server): void
    {
        $this->postMcp($this->client, $sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => [] === $params ? new stdClass() : $params], $server);
    }

    /**
     * Les requêtes SQL exécutées par la dernière requête HTTP (canal
     * `doctrine`, `debug` sous `kernel.debug`, comme en test), dans l'ordre :
     * le kernel redémarre entre deux requêtes, la sonde ne garde que la
     * dernière.
     *
     * @return list<string>
     */
    private function executedSql(): array
    {
        $queries = [];
        foreach (self::allChannelsLogRecordsOf('doctrine') as $record) {
            if (!str_starts_with($record->message, 'Executing ')) {
                continue;
            }

            $sql = $record->context['sql'] ?? $record->message;
            $queries[] = \is_string($sql) ? $sql : $record->message;
        }

        return $queries;
    }

    /**
     * @param list<string> $secrets
     * @param list<string> $expected « canal/message » qui doivent avoir été vus
     */
    private function assertNoLogQuotes(array $secrets, array $expected): void
    {
        $formatter = new JsonFormatter();
        $seen = [];

        foreach (self::allChannelsLogRecords() as $record) {
            if ('doctrine' === $record->channel && Level::Debug === $record->level) {
                continue;
            }

            $seen[] = $record->channel.'/'.$record->message;
            $line = $formatter->format($record);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $line, $record->channel.'/'.$record->message);
            }
        }

        foreach ($expected as $key) {
            self::assertContains($key, $seen);
        }
    }

    private function clearLogProbe(): void
    {
        foreach (self::getContainer()->get('monolog.logger.security_audit')->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                $handler->clear();
            }
        }
    }

    /** Un jeton de la forme de ceux de league (80 hexadécimaux minuscules), qu'aucune ligne ne porte. */
    private function unknownToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }
}
