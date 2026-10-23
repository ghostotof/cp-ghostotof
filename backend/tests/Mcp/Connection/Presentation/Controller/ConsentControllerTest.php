<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le consentement à une connexion MCP (#647, spec 0007 phase 2),
 * `GET|POST /api/oauth/consent`, de bout en bout par HTTP.
 *
 * - Le palier de confiance seul y entre (ADR 0006 D3) : un jeton d'accès de
 *   base, un compte `ROLE_USER` sans plus, un anonyme sont refusés ; un
 *   `ROLE_SUPER` y entre par la hiérarchie des rôles.
 * - La demande est revalidée à chaque appel, par le même validateur que le
 *   point d'autorisation : la page ne fait que renvoyer ce que le serveur a
 *   jugé, et une demande devenue invalide (redirection modifiée) est un 400
 *   problem+json — jamais une redirection, jamais un code.
 * - L'accord crée la connexion et son code dans une transaction, puis écrit
 *   `mcp-connection-granted` au journal de sécurité ; le refus ne crée rien.
 *
 * Les URL attendues sont recalculées depuis les paramètres de configuration,
 * jamais lues dans `OAuthEndpoints` : le test resterait vert sur une URL fausse
 * qu'il recopierait.
 */
final class ConsentControllerTest extends WebTestCase
{
    use HttpJson;
    use ReadsSecurityAuditLog;

    /** Les tables du modèle, des feuilles vers la racine, puis les comptes. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client', 'cpg_user'];

    private const string PATH = '/api/oauth/consent';

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    private const string CLIENT_NAME = 'Client de test';

    private const string LOOPBACK_REDIRECT = 'http://localhost:49152/callback';

    /** Un vérificateur PKCE conforme à RFC 7636 (43 caractères au moins). */
    private const string CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-test';

    private const string TRUSTED_USERNAME = 'trusted';
    private const string PLAIN_USERNAME = 'plain';
    private const string SUPER_USERNAME = 'super';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->purge();
        // `login_throttling` compte dans cache.rate_limiter, en base (ADR 0005)
        // et partagé d'un test à l'autre : chaque test repart de zéro.
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::getContainer()->get(McpClientRepositoryInterface::class)->add(
            McpClient::register(self::CLIENT_ID, self::CLIENT_NAME, ['http://localhost/callback', 'https://client.example.test/callback'], new DateTimeImmutable()),
        );
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * Ce que la personne voit avant d'accepter : le nom **déclaré** du client,
     * l'hôte où partira le code, le scope et la ressource.
     */
    public function testATrustedAccountSeesTheSummaryOfTheRequest(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame([
            'client' => ['id' => self::CLIENT_ID, 'name' => self::CLIENT_NAME, 'registration' => 'preregistered'],
            'redirectHost' => 'localhost',
            'scope' => 'nominative:read',
            'resource' => $this->resource(),
        ], $this->json($response));
        $this->assertNotStored($response);
    }

    /**
     * L'accord : une connexion pour ce compte et ce client, un code émis
     * pour elle, renvoyé au client sur la redirection enregistrée avec
     * `state` et `iss` (RFC 9207), puis la ligne d'audit — qui ne porte pas
     * le code.
     */
    public function testAnApprovalCreatesTheConnectionAndSendsTheCodeBackToTheClient(): void
    {
        $account = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $this->assertNotStored($response);
        $redirectUri = $this->redirectUriOf($response);
        self::assertStringStartsWith(self::LOOPBACK_REDIRECT.'?', $redirectUri);
        $query = $this->queryOf($redirectUri);
        self::assertIsString($query['code'] ?? null);
        self::assertNotSame('', $query['code']);
        self::assertSame('state-1', $query['state'] ?? null);
        self::assertSame($this->publicApiBase().'/api/oauth', $query['iss'] ?? null);
        self::assertArrayNotHasKey('error', $query);

        $connections = $this->connectionRows();
        self::assertCount(1, $connections);
        self::assertSame($account, $connections[0]['account_id']);
        self::assertSame(self::CLIENT_ID, $connections[0]['client_id']);
        self::assertSame('nominative:read', $connections[0]['scope']);
        self::assertNull($connections[0]['revoked_at']);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code WHERE connection_id = :id', ['id' => $connections[0]['id']]));

        $audit = self::singleSecurityAuditEvent('mcp-connection-granted');
        self::assertSame($account, $audit['userId'] ?? null);
        self::assertSame($connections[0]['id'], $audit['connectionId'] ?? null);
        self::assertSame(self::CLIENT_ID, $audit['clientId'] ?? null);
        self::assertSame($account, $audit['actor'] ?? null);
        self::assertSame(self::PATH, $audit['path'] ?? null);
        self::assertStringNotContainsString($query['code'], json_encode(self::securityAuditRecords(), \JSON_THROW_ON_ERROR));
    }

    /** Le refus : `access_denied` au client, `state` et `iss` compris, et rien de créé. */
    public function testADenialSendsAccessDeniedBackToTheClientAndCreatesNothing(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: false);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $this->assertNotStored($response);
        $redirectUri = $this->redirectUriOf($response);
        self::assertStringStartsWith(self::LOOPBACK_REDIRECT.'?', $redirectUri);
        $query = $this->queryOf($redirectUri);
        self::assertSame('access_denied', $query['error'] ?? null);
        self::assertSame('state-1', $query['state'] ?? null);
        self::assertSame($this->publicApiBase().'/api/oauth', $query['iss'] ?? null);
        self::assertArrayNotHasKey('code', $query);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code'));
        self::assertSame([], self::securityAuditEvents('mcp-connection-granted'));
    }

    /** `ROLE_SUPER` hérite du palier de confiance (`role_hierarchy`). */
    public function testASuperAdministratorMayGrantAConnectionThroughTheRoleHierarchy(): void
    {
        $account = $this->loginAs(self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);

        $response = $this->decide(approved: true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertArrayHasKey('code', $this->queryOf($this->redirectUriOf($response)));
        self::assertSame($account, $this->connectionRows()[0]['account_id'] ?? null);
    }

    /**
     * La redirection modifiée après l'autorisation (la page, ou quiconque
     * rejoue le POST, en propose une autre que celle validée) : la demande
     * revalidée ne l'est plus. Un 400 problem+json, jamais une redirection
     * vers l'URI proposée, ni vers une autre, et rien de créé.
     *
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function requestsNoLongerValid(): iterable
    {
        yield 'redirection non enregistrée' => [['redirect_uri' => 'https://attacker.example.test/callback']];
        yield 'client inconnu' => [['client_id' => 'https://unknown.example.test/metadata.json']];
        yield 'ressource absente' => [['resource' => null]];
        yield 'ressource étrangère' => [['resource' => 'https://other.example.test/api/mcp']];
        yield 'PKCE plain' => [['code_challenge_method' => 'plain']];
        yield 'sans défi PKCE' => [['code_challenge' => null, 'code_challenge_method' => null]];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('requestsNoLongerValid')]
    public function testARequestNoLongerValidIsA400ProblemWithoutRedirectNorConnection(array $overrides): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        foreach ([true, false] as $approved) {
            $response = $this->decide(approved: $approved, overrides: $overrides);

            $this->assertRejected($response);
        }

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code'));
    }

    /**
     * Le résumé d'une demande invalide : le même 400, jamais le JSON de league.
     *
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('requestsNoLongerValid')]
    public function testTheSummaryOfARequestNoLongerValidIsTheSame400Problem(array $overrides): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters($overrides)));

        $this->assertRejected($this->client->getResponse());
    }

    /** Le jeton d'accès de base (ADR 0003 D6) n'est pas le palier de confiance. */
    public function testTheBaseTierIsForbidden(): void
    {
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        $this->assertBothRoutesAnswer(Response::HTTP_FORBIDDEN, $this->xsrfToken());
    }

    /** Un compte réel sans `ROLE_TRUSTED` : le palier de base, rien de plus. */
    public function testAnAccountWithoutRoleTrustedIsForbidden(): void
    {
        $this->loginAs(self::PLAIN_USERNAME, []);

        $this->assertBothRoutesAnswer(Response::HTTP_FORBIDDEN, $this->xsrfToken());
    }

    /**
     * Anonyme : le firewall répond 401 au GET ; le POST est refusé avant lui
     * par la garde CSRF (priorité 20), 403.
     */
    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->post(['approved' => true, ...$this->decisionBody()], null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /**
     * Une mutation sans l'en-tête du double-submit CSRF : refusée, même au
     * palier de confiance — et par la garde CSRF, qui le trace.
     */
    public function testAnApprovalWithoutTheCsrfHeaderIsForbidden(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->post(['approved' => true, ...$this->decisionBody()], null);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        $rejection = self::singleSecurityAuditEvent('csrf-rejected');
        self::assertSame(self::PATH, $rejection['path'] ?? null);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /** Une décision sans `approved` n'est ni un accord ni un refus : 422, rien de créé. */
    public function testADecisionWithoutApprovedIsUnprocessable(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->post($this->decisionBody(), $this->xsrfToken());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    private function assertBothRoutesAnswer(int $status, ?string $xsrfToken): void
    {
        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), 'GET');

        $this->post(['approved' => true, ...$this->decisionBody()], $xsrfToken);
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), 'POST');
        // L'en-tête XSRF est valide : le refus est celui de l'access_control,
        // jamais celui de la garde CSRF, qui l'aurait tracé (`csrf-rejected`).
        self::assertSame([], self::securityAuditEvents('csrf-rejected'), 'Le POST doit passer la garde CSRF et buter sur l\'access_control.');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /**
     * Le refus de revalidation : 400 problem+json typé, sans `redirectUri` ni
     * `Location` — on ne redirige jamais au consentement une demande que le
     * serveur ne juge plus valide.
     */
    private function assertRejected(Response $response): void
    {
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->has('Location'));
        $body = $this->json($response);
        self::assertSame('/errors/authorization-request-rejected', $body['type'] ?? null);
        self::assertArrayNotHasKey('redirectUri', $body);
        self::assertArrayNotHasKey('error', $body);
    }

    /**
     * @param array<string, string|null> $overrides paramètres de la demande, sous leur nom OAuth
     */
    private function decide(bool $approved, array $overrides = []): Response
    {
        $this->post(['approved' => $approved, ...$this->decisionBody($overrides)], $this->xsrfToken());

        return $this->client->getResponse();
    }

    /**
     * La demande telle que la page la renvoie : les paramètres validés reçus
     * du point d'autorisation, sous les noms du corps JSON.
     *
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    private function decisionBody(array $overrides = []): array
    {
        $names = [
            'response_type' => 'responseType',
            'client_id' => 'clientId',
            'redirect_uri' => 'redirectUri',
            'scope' => 'scope',
            'state' => 'state',
            'code_challenge' => 'codeChallenge',
            'code_challenge_method' => 'codeChallengeMethod',
            'resource' => 'resource',
        ];
        $body = [];

        foreach ($this->validParameters($overrides) as $name => $value) {
            $body[$names[$name]] = $value;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, ?string $xsrfToken): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if (null !== $xsrfToken) {
            $server['HTTP_X_XSRF_TOKEN'] = $xsrfToken;
        }

        $this->client->request('POST', self::PATH, server: $server, content: self::jsonBody($body));
    }

    /**
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    private function validParameters(array $overrides = []): array
    {
        $parameters = [
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'scope' => 'nominative:read',
            'state' => 'state-1',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => $this->resource(),
            ...$overrides,
        ];

        return array_filter($parameters, static fn(?string $value): bool => null !== $value);
    }

    /**
     * Crée le compte et le connecte ; renvoie son UUID.
     *
     * @param list<string> $roles
     */
    private function loginAs(string $username, array $roles): string
    {
        $password = [] === $roles ? TestCredentials::plainPassword() : TestCredentials::superPassword();
        $account = self::getContainer()->get(CpgUserRegistrarInterface::class)->register($username, $password, $roles);

        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        return $account->getId()->toRfc4122();
    }

    private function xsrfToken(): string
    {
        return $this->client->getCookieJar()->get('XSRF-TOKEN')?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }

    private function redirectUriOf(Response $response): string
    {
        $body = $this->json($response);
        self::assertSame(['redirectUri'], array_keys($body));
        self::assertIsString($body['redirectUri']);

        return $body['redirectUri'];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function queryOf(string $uri): array
    {
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * Les connexions en base, avec l'identifiant public de leur client.
     *
     * @return list<array{id: string, account_id: string, client_id: string, scope: string, revoked_at: string|null}>
     */
    private function connectionRows(): array
    {
        /** @var list<array{id: string, account_id: string, client_id: string, scope: string, revoked_at: string|null}> */
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT c.id, c.account_id, cl.client_id, c.scope, c.revoked_at FROM mcp_connection c INNER JOIN mcp_client cl ON cl.id = c.mcp_client_id',
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function countRows(string $sql, array $parameters = []): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne($sql, $parameters);
        self::assertIsInt($count);

        return $count;
    }

    private function assertNotStored(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers->get('Cache-Control'));
    }

    private function resource(): string
    {
        return $this->publicApiBase().'/api/mcp';
    }

    /**
     * La base publique de l'API telle que le conteneur de test la résout
     * (`phpunit.dist.xml` la force à vide : elle retombe sur celle du
     * frontend), barre finale retirée.
     */
    private function publicApiBase(): string
    {
        $base = self::getContainer()->getParameter('app.public_api_base_url');
        self::assertIsString($base);
        self::assertNotSame('', $base);

        return rtrim($base, '/');
    }

    private function purge(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $em->clear();
    }
}
