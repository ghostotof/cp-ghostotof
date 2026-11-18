<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Infrastructure\Cimd\FetchQuotaKey;
use App\Mcp\Connection\Infrastructure\Cimd\HostAddressResolverInterface;
use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\Support\FixedHostAddressResolver;
use App\Tests\Support\ReadsSecurityAuditLog;
use Generator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Le consentement à la connexion d'un client MCP décrit par son document
 * (CIMD, #651), de bout en bout : le résumé montre le domaine que le document
 * prouve, **rien n'est écrit avant l'approbation**, et l'approbation écrit la
 * ligne `mcp_client` sur laquelle le point de jeton et le rafraîchissement
 * jugent ensuite, sans nouvelle récupération.
 *
 * Mêmes doublures sortantes que CimdAuthorizationTest : un `MockHttpClient`
 * dans le filet de `services.yaml`, un résolveur d'adresses écrit par le test,
 * aucun accès réseau.
 */
final class CimdConsentFlowTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsSecurityAuditLog;

    private const string HTTP_CLIENT_SERVICE = 'app.mcp.cimd.http_client';

    /** Le port loopback aléatoire que Claude Code choisit à chaque connexion (RFC 8252 §7.3). */
    private const string CIMD_REDIRECT = 'http://localhost:53124/callback';

    private const string DECLARED_REDIRECTS = '["http://localhost/callback","http://127.0.0.1/callback"]';

    /** Le nombre de requêtes sortantes parties vers le « réseau ». */
    private int $outboundRequests = 0;

    /** Le document que le « réseau » sert : un test le change entre deux étapes. */
    private string $served = '';

    /** Vrai : l'hôte du document cale (délai d'inactivité dépassé), un refus passager. */
    private bool $stalling = false;

    /** Le client HTTP n'est remplacé qu'une fois par test : le conteneur refuse de remplacer un service initialisé. */
    private bool $networkInstalled = false;

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
        // Le journal d'audit se relit sur plusieurs requêtes d'un même test.
        $this->client->disableReboot();
        $this->clearPools();
        $this->outboundRequests = 0;
        $this->networkInstalled = false;
        $this->stalling = false;
        $this->served = ClaudeCodeMetadata::json();
        $this->installTheNetwork();
    }

    protected function tearDown(): void
    {
        $this->clearPools();
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testTheSummaryShowsTheVerifiedDomainAndTheDeclaredName(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $summary = $this->consentSummary($this->cimdParameters());

        self::assertSame(
            ['id' => ClaudeCodeMetadata::CLIENT_ID, 'name' => 'Claude Code', 'registration' => 'cimd', 'domain' => 'claude.ai'],
            $summary['client'],
        );
        self::assertSame('localhost', $summary['redirectHost']);
    }

    public function testAPreregisteredClientHasNoVerifiedDomain(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $summary = $this->consentSummary($this->validParameters());

        $client = $summary['client'];
        self::assertIsArray($client);
        self::assertSame('preregistered', $client['registration']);
        self::assertArrayHasKey('domain', $client);
        self::assertNull($client['domain']);
        self::assertSame(0, $this->outboundRequests);
    }

    public function testNoClientRowExistsBeforeApproval(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->consentSummary($this->cimdParameters());
        self::assertSame(0, $this->cimdRows(), 'Le résumé n\'écrit rien.');

        $refusal = $this->decide(approved: false, overrides: $this->cimdOverrides());

        self::assertSame(Response::HTTP_OK, $refusal->getStatusCode(), substr((string) $refusal->getContent(), 0, 300));
        self::assertStringContainsString('error=access_denied', $this->redirectUriOf($refusal));
        self::assertSame(0, $this->cimdRows(), 'Un refus de la personne n\'enregistre pas le client.');
    }

    /**
     * Le parcours de Claude Code : un port loopback tiré au hasard, l'approbation
     * qui écrit la ligne, puis l'échange et le rafraîchissement qui la jugent —
     * avec une seule requête sortante sur tout le parcours.
     */
    public function testClaudeCodeCompletesTheFlowOnARandomLoopbackPort(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->consentSummary($this->cimdParameters());

        $approval = $this->decide(approved: true, overrides: $this->cimdOverrides());

        self::assertSame(Response::HTTP_OK, $approval->getStatusCode(), substr((string) $approval->getContent(), 0, 300));
        $callback = $this->redirectUriOf($approval);
        self::assertStringStartsWith(self::CIMD_REDIRECT.'?', $callback);
        $received = $this->queryOf($callback);
        self::assertNotSame('', $received['code'] ?? '');
        self::assertSame('state-1', $received['state'] ?? null);
        self::assertArrayHasKey('iss', $received);
        self::assertSame(1, $this->cimdRows());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_client WHERE registration = :registration AND name = :name', ['registration' => 'cimd', 'name' => 'Claude Code']));

        $outbound = $this->outboundRequests;
        $code = $received['code'];
        self::assertIsString($code);
        $exchange = $this->exchange($code, $this->cimdOverrides());

        self::assertSame(Response::HTTP_OK, $exchange->getStatusCode(), substr((string) $exchange->getContent(), 0, 300));
        $tokens = $this->json($exchange);
        self::assertIsString($tokens['refresh_token'] ?? null);

        $refreshed = $this->refresh($tokens['refresh_token'], ['client_id' => ClaudeCodeMetadata::CLIENT_ID]);

        self::assertSame(Response::HTTP_OK, $refreshed->getStatusCode(), substr((string) $refreshed->getContent(), 0, 300));
        self::assertSame($outbound, $this->outboundRequests, 'Le point de jeton ne récupère aucun document.');
        self::assertSame(1, $this->outboundRequests, 'Une seule requête sortante : le cache sert les revalidations.');
    }

    public function testASecondApprovalRefreshesTheRowInsteadOfDuplicating(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->approve();
        self::assertSame(1, $this->cimdRows());

        // Le client a renommé son document ; le cache est vidé : la prochaine demande le relit.
        $this->clearPools();
        $this->served = str_replace('"client_name":"Claude Code"', '"client_name":"Claude Code 2"', ClaudeCodeMetadata::json());
        $this->approve();

        self::assertSame(1, $this->cimdRows(), 'Une seule ligne par client.');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_client WHERE registration = :registration AND name = :name', ['registration' => 'cimd', 'name' => 'Claude Code 2']));
    }

    /**
     * Le document qui retire la redirection entre l'affichage et l'approbation
     * n'accorde rien : la décision est revalidée sur le document d'alors.
     */
    public function testADocumentThatDroppedTheRedirectBetweenDisplayAndApprovalGrantsNothing(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->consentSummary($this->cimdParameters());

        $this->clearPools();
        $this->served = str_replace(self::DECLARED_REDIRECTS, '["https://claude.ai/callback"]', ClaudeCodeMetadata::json());
        $response = $this->decide(approved: true, overrides: $this->cimdOverrides());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        self::assertSame('/errors/authorization-request-rejected', $this->json($response)['type'] ?? null);
        self::assertNull($response->headers->get('Location'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame(0, $this->cimdRows());
    }

    public function testTheGrantAuditCarriesThePublicClientId(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->approve();

        $audit = self::singleSecurityAuditEvent('mcp-connection-granted');
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $audit['clientId']);
        self::assertLessThanOrEqual(255, \strlen($audit['clientId']));
    }

    /**
     * Un quota épuisé à l'affichage est un 429 avec le délai du limiteur, comme
     * au point d'autorisation (décision du 2026-11-16) : la personne peut
     * réessayer, ce n'est pas une demande invalide.
     */
    public function testAnExhaustedQuotaAtDisplayIsATooManyRequestsWithItsRetryAfter(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $received = $this->receivedQuery();
        $this->clearPools();
        $this->exhaustTheFetchQuota();

        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($received));
        $response = $this->client->getResponse();

        $this->assertTransientRefusal($response, Response::HTTP_TOO_MANY_REQUESTS);
        $retryAfter = $response->headers->get('Retry-After');
        self::assertNotNull($retryAfter, 'Un 429 annonce quand revenir.');
        /** @var RateLimiterFactory $factory */
        $factory = self::getContainer()->get('limiter.mcp_client_metadata');
        $expected = $factory->create(FetchQuotaKey::forAddress('127.0.0.1'))->consume(0)->getRetryAfter()->getTimestamp() - time();
        self::assertEqualsWithDelta($expected, (int) $retryAfter, 1.0, 'Retry-After est celui du limiteur.');
        self::assertGreaterThan(0, (int) $retryAfter);
    }

    /** Un hôte qui cale à l'affichage est un 503, sans `Retry-After` : rien ne dit quand il répondra. */
    public function testAStallingHostAtDisplayIsServiceUnavailableWithoutRetryAfter(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $received = $this->receivedQuery();
        $this->clearPools();
        $this->stalling = true;

        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($received));

        $this->assertTransientRefusal($this->client->getResponse(), Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /** À l'approbation, le quota épuisé est un 429 et n'écrit rien : ni client, ni connexion, ni audit d'accord. */
    public function testAnExhaustedQuotaAtApprovalIsATooManyRequestsAndWritesNothing(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->consentSummary($this->cimdParameters());
        $this->clearPools();
        $this->exhaustTheFetchQuota();

        $response = $this->decide(approved: true, overrides: $this->cimdOverrides());

        $this->assertTransientRefusal($response, Response::HTTP_TOO_MANY_REQUESTS);
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertNothingWritten();
    }

    /** À l'approbation, un hôte qui cale est un 503 sans `Retry-After` et n'écrit rien. */
    public function testAStallingHostAtApprovalIsServiceUnavailableAndWritesNothing(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->consentSummary($this->cimdParameters());
        $this->clearPools();
        $this->stalling = true;

        $response = $this->decide(approved: true, overrides: $this->cimdOverrides());

        $this->assertTransientRefusal($response, Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertNull($response->headers->get('Retry-After'));
        $this->assertNothingWritten();
    }

    /**
     * Une décision montrée à un autre compte est un 409, même quand la revalidation
     * aurait été refusée passagèrement (quota épuisé) : le jugement du corps et du
     * compte précède toute récupération du document (#651, F5).
     */
    public function testADecisionShownToAnotherAccountIsConflictEvenWithAnExhaustedQuota(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->consentSummary($this->cimdParameters());
        $this->clearPools();
        $this->exhaustTheFetchQuota();
        $before = $this->outboundRequests;

        $response = $this->decide(approved: true, overrides: $this->cimdOverrides(), shownAccount: Uuid::v7()->toRfc4122());

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        self::assertSame($before, $this->outboundRequests, 'Aucune récupération du document.');
        $this->assertNothingWritten();
    }

    /** Même verdict avec un hôte qui cale : 409, pas 503, et aucune récupération. */
    public function testADecisionShownToAnotherAccountIsConflictEvenWithAStallingHost(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->consentSummary($this->cimdParameters());
        $this->clearPools();
        $this->stalling = true;
        $before = $this->outboundRequests;

        $response = $this->decide(approved: true, overrides: $this->cimdOverrides(), shownAccount: Uuid::v7()->toRfc4122());

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        self::assertSame($before, $this->outboundRequests, 'Aucune récupération du document.');
        $this->assertNothingWritten();
    }

    /** Un refus définitif (document sans la redirection) reste le 400 générique : seul le passager change. */
    public function testADefinitiveRefusalAtDisplayStaysTheGenericBadRequest(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $received = $this->receivedQuery();
        $this->clearPools();
        $this->served = str_replace(self::DECLARED_REDIRECTS, '["https://claude.ai/callback"]', ClaudeCodeMetadata::json());

        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($received));
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('/errors/authorization-request-rejected', $this->json($response)['type'] ?? null);
        self::assertNull($response->headers->get('Retry-After'));
    }

    /**
     * La réponse ne dit jamais la cause (décision du 2026-11-15) : le même
     * message pour le quota et pour l'hôte, aucun mot de la cause.
     */
    public function testTheTransientRefusalNeverNamesItsCause(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $received = $this->receivedQuery();

        $this->clearPools();
        $this->exhaustTheFetchQuota();
        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($received));
        $throttled = $this->json($this->client->getResponse());

        $this->clearPools();
        $this->stalling = true;
        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($received));
        $stalled = $this->json($this->client->getResponse());

        self::assertArrayHasKey('type', $throttled);
        self::assertArrayHasKey('detail', $throttled);
        self::assertSame('/errors/consent-temporarily-unavailable', $throttled['type']);
        self::assertSame($throttled['detail'], $stalled['detail'] ?? 'absent', 'Un message constant, le même pour les deux causes.');
        self::assertSame($throttled['type'], $stalled['type'] ?? 'absent');
        self::assertIsString($throttled['detail']);
        self::assertDoesNotMatchRegularExpression('/quota|limit|timeout|stall|host|h\xc3\xb4te|d\xc3\xa9lai|rate/i', $throttled['detail']);
    }

    /** Une réponse de refus passager : le code, un problème typé qui n'est pas le 400, aucune redirection. */
    private function assertTransientRefusal(Response $response, int $status): void
    {
        self::assertSame($status, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        $body = $this->json($response);
        self::assertSame('/errors/consent-temporarily-unavailable', $body['type'] ?? null);
        self::assertNull($response->headers->get('Location'));
        self::assertIsString($body['detail'] ?? null);
    }

    private function assertNothingWritten(): void
    {
        self::assertSame(0, $this->cimdRows(), 'Aucune ligne mcp_client.');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'), 'Aucune connexion.');
        self::assertSame([], self::securityAuditEvents('mcp-connection-granted'), 'Aucun audit d\'accord.');
    }

    /**
     * Le point d'autorisation, puis la requête que la page reçoit sur la redirection.
     *
     * @return array<string, string>
     */
    private function receivedQuery(): array
    {
        $this->client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($this->cimdParameters()));
        $redirect = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $redirect->getStatusCode(), substr((string) $redirect->getContent(), 0, 300));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), \PHP_URL_QUERY), $received);

        /** @var array<string, string> $received */
        return $received;
    }

    /** Dix récupérations d'autres `client_id` : le quota d'une adresse est épuisé, le onzième fetch est refusé. */
    private function exhaustTheFetchQuota(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($this->cimdParameters(['client_id' => 'https://claude.ai/oauth/client-'.$i])));
        }
    }

    /** Autorisation, résumé, puis approbation, par le compte connecté. */
    private function approve(): void
    {
        $this->consentSummary($this->cimdParameters());
        $response = $this->decide(approved: true, overrides: $this->cimdOverrides());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
    }

    /**
     * Le point d'autorisation, puis le résumé lu avec la requête que la page
     * reçoit sur la redirection (comme OAuthFlowTest).
     *
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    private function consentSummary(array $parameters): array
    {
        $this->client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($parameters));
        $redirect = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $redirect->getStatusCode(), substr((string) $redirect->getContent(), 0, 300));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), \PHP_URL_QUERY), $received);

        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($received));
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));

        return $this->json($response);
    }

    /**
     * @return array<string, string>
     */
    private function cimdOverrides(): array
    {
        return ['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => self::CIMD_REDIRECT];
    }

    /**
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    private function cimdParameters(array $overrides = []): array
    {
        return $this->validParameters([...$this->cimdOverrides(), ...$overrides]);
    }

    private function cimdRows(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM mcp_client WHERE registration = :registration', ['registration' => 'cimd']);
    }

    /**
     * Remplace le client HTTP de la récupération par un `MockHttpClient`
     * entouré du filet de `services.yaml`, et le résolveur d'adresses par une
     * table où `claude.ai` est public. Le document servi est `$this->served`,
     * relu à chaque requête.
     */
    private function installTheNetwork(): void
    {
        if ($this->networkInstalled) {
            return;
        }

        $this->networkInstalled = true;
        $answer = function (): ResponseInterface {
            ++$this->outboundRequests;

            if ($this->stalling) {
                // Une chaîne vide dans le corps d'un MockResponse est un délai d'inactivité dépassé.
                return new MockResponse((static function (): Generator {
                    yield '';
                })(), ['response_headers' => ['Content-Type: application/json']]);
            }

            return new MockResponse($this->served, ['response_headers' => ['Content-Type: application/json']]);
        };

        self::getContainer()->set(self::HTTP_CLIENT_SERVICE, new NoPrivateNetworkHttpClient(new MockHttpClient($answer(...)), PublicAddressPolicy::SUBNETS));
        self::getContainer()->set(HostAddressResolverInterface::class, new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]));
    }

    private function clearPools(): void
    {
        self::getContainer()->get('cache.mcp_client_metadata')->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
}
