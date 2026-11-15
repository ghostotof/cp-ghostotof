<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Application\Cimd\CimdClientAdmissionInterface;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Infrastructure\Cimd\FetchQuotaKey;
use App\Mcp\Connection\Infrastructure\Cimd\HostAddressResolverInterface;
use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\Support\FixedHostAddressResolver;
use App\Tests\Support\ReadsSecurityAuditLog;
use Closure;
use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Un client MCP décrit par son document (CIMD, #651), de bout en bout au point
 * d'autorisation : la requête HTTP traverse le validateur, l'admission, la
 * récupération sécurisée du document, puis league, sans aucun accès réseau.
 *
 * Le client HTTP de la récupération est remplacé par un `MockHttpClient` entouré
 * du même filet que `services.yaml` (`NoPrivateNetworkHttpClient`, liste de
 * `PublicAddressPolicy`) : la couche 5 reste en jeu. Le résolveur d'adresses est
 * une table écrite par le test. Chaque test compte les requêtes sortantes.
 *
 * Un refus d'un client ou d'une redirection n'est jamais une redirection : pas
 * de `Location`, ce serait renvoyer la personne vers une URI que rien n'a validée.
 */
final class CimdAuthorizationTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsSecurityAuditLog;

    private const string HTTP_CLIENT_SERVICE = 'app.mcp.cimd.http_client';

    private const string CIMD_REDIRECT = 'http://localhost:53124/callback';

    /** Le nombre de requêtes sortantes parties vers le « réseau ». */
    private int $outboundRequests = 0;

    /** Installé une seule fois par test : le conteneur refuse de remplacer un service déjà initialisé. */
    private ?FixedHostAddressResolver $resolver = null;

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
        // Le journal d'audit se relit sur plusieurs requêtes d'un même test.
        $this->client->disableReboot();
        $this->clearPools();
        $this->resolver = null;
        $this->outboundRequests = 0;
    }

    protected function tearDown(): void
    {
        $this->clearPools();
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testAnAllowedCimdClientReachesTheConsentPage(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));

        $response = $this->authorize($this->cimdParameters());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringContainsString('/oauth/consent?', (string) $response->headers->get('Location'));
        self::assertSame(1, $this->outboundRequests);
        self::assertSame([], self::securityAuditEvents('mcp-client-metadata-refused'));
    }

    public function testADomainOutsideTheListIsAnUnknownClient(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));

        $response = $this->authorize($this->cimdParameters(['client_id' => 'https://client.evil.test/metadata.json']));

        $this->assertInvalidClient($response);
        self::assertSame(0, $this->outboundRequests);
        self::assertSame([], $this->resolver()->resolvedHosts());
        self::assertSame([], self::securityAuditEvents('mcp-client-metadata-refused'), 'Un hôte hors liste est choisi par l\'appelant : jamais tracé.');
    }

    public function testADocumentOnAPrivateAddressIsRefused(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));
        $this->resolveHostsTo(['claude.ai' => ['10.0.0.5']]);

        $response = $this->authorize($this->cimdParameters());

        $this->assertInvalidClient($response);
        self::assertSame(0, $this->outboundRequests);
        $audit = self::singleSecurityAuditEvent('mcp-client-metadata-refused');
        self::assertSame('claude.ai', $audit['clientHost']);
        self::assertSame('address-blocked', $audit['cause']);
    }

    public function testARedirectingDocumentIsRefused(): void
    {
        $this->serve(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location: http://169.254.169.254/'],
        ]));

        $response = $this->authorize($this->cimdParameters());

        $this->assertInvalidClient($response);
        self::assertSame(1, $this->outboundRequests, 'La redirection n\'est pas suivie.');
        self::assertSame('redirected', self::singleSecurityAuditEvent('mcp-client-metadata-refused')['cause']);
    }

    public function testAnOversizedDocumentIsRefused(): void
    {
        $this->serve(static fn (): MockResponse => self::document(str_repeat('a', 6000)));

        $response = $this->authorize($this->cimdParameters());

        $this->assertInvalidClient($response);
        self::assertSame('too-large', self::singleSecurityAuditEvent('mcp-client-metadata-refused')['cause']);
    }

    /**
     * Un hôte qui cale n'est pas un client inconnu : la personne peut réessayer
     * (`temporarily_unavailable`, 503), sans redirection.
     */
    public function testAStallingDocumentIsTemporarilyUnavailable(): void
    {
        $this->serve(static fn (): MockResponse => new MockResponse(
            (static function (): Generator {
                // Une chaîne vide dans le corps d'un MockResponse est un délai d'inactivité dépassé.
                yield '';
            })(),
            ['response_headers' => ['Content-Type: application/json']],
        ));

        $response = $this->authorize($this->cimdParameters());

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertSame('temporarily_unavailable', $this->json($response)['error']);
    }

    /**
     * Le onzième `client_id` d'une même adresse est refusé en 429, sans
     * requête sortante de plus, et le délai du limiteur est annoncé.
     */
    public function testAnExhaustedQuotaIsATooManyRequestsWithItsRetryAfter(): void
    {
        $this->serve(static fn (): MockResponse => new MockResponse('', ['http_code' => 404]));

        for ($i = 1; $i <= 10; ++$i) {
            $this->assertInvalidClient($this->authorize($this->cimdParameters(['client_id' => 'https://claude.ai/oauth/client-'.$i])));
        }

        $response = $this->authorize($this->cimdParameters(['client_id' => 'https://claude.ai/oauth/client-11']));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertSame('temporarily_unavailable', $this->json($response)['error']);
        self::assertSame(10, $this->outboundRequests);

        $retryAfter = $response->headers->get('Retry-After');
        self::assertNotNull($retryAfter, 'Un 429 annonce quand revenir.');

        /** @var RateLimiterFactory $factory */
        $factory = self::getContainer()->get('limiter.mcp_client_metadata');
        $limit = $factory->create(FetchQuotaKey::forAddress('127.0.0.1'))->consume(0);
        $expected = $limit->getRetryAfter()->getTimestamp() - time();
        self::assertEqualsWithDelta($expected, (int) $retryAfter, 1.0, 'Retry-After est celui du limiteur.');
        self::assertGreaterThan(0, (int) $retryAfter);
    }

    public function testARedirectUriTheDocumentDoesNotDeclareIsRefused(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));

        $response = $this->authorize($this->cimdParameters(['redirect_uri' => 'https://evil.test/cb']));

        self::assertNull($response->headers->get('Location'));
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    /**
     * Un attribut de requête ne se contrefait pas par la requête : le pont
     * PSR-7 réel ne lit que la requête HTTP, et l'admission écrit l'attribut
     * après lui. Le paramètre est donc sans effet sur un client inconnu.
     */
    public function testAQueryParameterNamedLikeTheAdmissionAttributeChangesNothing(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));
        $unknown = $this->cimdParameters(['client_id' => 'https://client.evil.test/metadata.json']);

        $without = $this->authorize($unknown);
        $with = $this->authorize($unknown, CimdClientAdmissionInterface::REQUEST_ATTRIBUTE.'=x');

        $this->assertInvalidClient($with);
        self::assertSame($without->getStatusCode(), $with->getStatusCode());
        self::assertSame($without->getContent(), $with->getContent());
        self::assertSame(0, $this->outboundRequests);
    }

    /**
     * league rogne `client_id` avant de le lire : un identifiant entouré de
     * blancs désignerait la ligne `cimd` d'un client déjà admis, que l'admission
     * (qui lit la valeur brute, sans la forme d'un `client_id` CIMD) n'a pas
     * relue. Le domaine a pu quitter la liste, le document changer : la ligne
     * périmée serait servie sans que rien l'ait jugée. Refusé, jamais corrigé
     * (comme au point de jeton, #725), sans redirection, avant toute récupération.
     */
    public function testAClientIdWithSurroundingBlanksIsRefusedWithoutServingTheStaleRow(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));
        $this->aDiscoveredRowFromAnEarlierAuthorization();

        // Chaque blanc que trim() rogne, en tête et en queue ; `\0` part tel quel
        // (%00) dans la requête et doit arriver comme tel (sinon PHP l'aurait
        // retiré, et la demande, valable, redirigerait vers le consentement).
        $padded = [];
        foreach ([' ', "\t", "\n", "\r", "\0", "\x0B"] as $blank) {
            $padded[] = $blank.ClaudeCodeMetadata::CLIENT_ID;
            $padded[] = ClaudeCodeMetadata::CLIENT_ID.$blank;
        }

        foreach ($padded as $clientId) {
            $label = bin2hex($clientId);
            $response = $this->authorize($this->cimdParameters(['client_id' => $clientId]));

            self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $label);
            self::assertNull($response->headers->get('Location'), 'Un refus d\'avant league n\'est jamais une redirection.');
            self::assertSame('invalid_request', $this->json($response)['error'], $label);
        }

        self::assertSame(0, $this->outboundRequests);
        self::assertSame([], $this->resolver()->resolvedHosts());
    }

    /**
     * Une ligne `cimd` stockée dont l'identifiant est valable pour
     * McpClientId::fromString() mais hors de la forme CIMD (écrite à la main, ou
     * avant une règle plus stricte) : la vraie admission rend `null` (l'identifiant
     * n'est pas de forme CIMD), league lit pourtant la ligne, et le garde la refuse
     * en `invalid_client`, sans redirection, avec un seul audit dont l'hôte est
     * `unreadable`. Rien n'est simulé : ni l'admission, ni le dépôt, ni le journal.
     *
     * @param non-empty-string $storedClientId
     */
    #[DataProvider('clientIdsOutsideTheCimdForm')]
    public function testAStoredCimdRowOutsideTheCimdFormIsRefusedAndAuditedAsUnreadable(string $storedClientId): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));
        // Le résolveur se remplace avant que l'admission, qui en dépend, soit instanciée.
        $this->resolver();
        self::assertNull(
            self::getContainer()->get(CimdClientAdmissionInterface::class)->admit($storedClientId),
            'Précondition : la vraie admission ne reconnaît pas cet identifiant comme un identifiant CIMD.',
        );
        $this->aStoredCimdRowWhoseClientIdIs($storedClientId);

        $response = $this->authorize($this->cimdParameters(['client_id' => $storedClientId]));

        $this->assertInvalidClient($response);
        self::assertSame(0, $this->outboundRequests);
        self::assertSame('unreadable', self::singleSecurityAuditEvent('mcp-client-row-not-admitted')['clientHost']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clientIdsOutsideTheCimdForm(): iterable
    {
        yield 'sans chemin' => ['https://claude.ai'];
        yield 'avec un port' => ['https://claude.ai:8443/oauth/client.json'];
    }

    /**
     * La décision du consentement revalide la demande par le même chemin
     * (ConsentService::decide()) : un `client_id` entouré de blancs y est
     * refusé de même, rien n'est accordé ni écrit, la ligne périmée n'est pas servie.
     */
    public function testAConsentDecisionForAClientIdWithSurroundingBlanksIsRejected(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));
        $this->aDiscoveredRowFromAnEarlierAuthorization();
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: true, overrides: ['client_id' => ' '.ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => self::CIMD_REDIRECT]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        self::assertSame('/errors/authorization-request-rejected', $this->json($response)['type'] ?? null);
        self::assertNull($response->headers->get('Location'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        // Refusé avant l'admission : aucune récupération du document.
        self::assertSame(0, $this->outboundRequests);

        // Témoin : la même décision, au `client_id` exact, est accordée (le
        // document est alors récupéré). Seul le blanc du `client_id` a donc
        // fait refuser la première, rien d'autre dans la demande.
        $control = $this->decide(approved: true, overrides: ['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => self::CIMD_REDIRECT]);

        self::assertSame(Response::HTTP_OK, $control->getStatusCode(), substr((string) $control->getContent(), 0, 300));
        self::assertSame(1, $this->outboundRequests);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /**
     * Le point de jeton n'a aucune admission : l'échange du code d'un client
     * `cimd` lit la ligne en base, que le consentement a créée. La garde du
     * point d'autorisation ne doit pas l'atteindre.
     */
    public function testACimdClientExchangesItsCodeAtTheTokenEndpointFromItsStoredRow(): void
    {
        $this->serve(fn (): MockResponse => self::document(ClaudeCodeMetadata::json()));
        $this->aDiscoveredRowFromAnEarlierAuthorization();
        $overrides = ['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => self::CIMD_REDIRECT];

        $code = $this->grantedCode($overrides);
        $outbound = $this->outboundRequests;
        $response = $this->exchange($code, $overrides);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        self::assertArrayHasKey('access_token', $this->json($response));
        self::assertSame($outbound, $this->outboundRequests, 'Le point de jeton ne récupère aucun document.');
    }

    public function testThePreregisteredFlowMakesNoOutboundCall(): void
    {
        $this->serve(static fn (): never => self::fail('Un client pré-enregistré ne déclenche aucune requête sortante.'));

        $response = $this->authorize($this->validParameters());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame(0, $this->outboundRequests);
        self::assertSame([], $this->resolver()->resolvedHosts());
    }

    /**
     * Remplace le client HTTP de la récupération par un `MockHttpClient`
     * entouré du filet de `services.yaml`.
     *
     * @param Closure(): ResponseInterface $answer la réponse du « réseau » à chaque requête sortante
     */
    private function serve(Closure $answer): void
    {
        $mock = new MockHttpClient(function () use ($answer): ResponseInterface {
            ++$this->outboundRequests;

            return $answer();
        });

        self::getContainer()->set(self::HTTP_CLIENT_SERVICE, new NoPrivateNetworkHttpClient($mock, PublicAddressPolicy::SUBNETS));
    }

    /**
     * @param array<string, list<string>> $addresses les adresses que le résolveur donne à chaque hôte
     */
    private function resolveHostsTo(array $addresses): void
    {
        $this->resolver = new FixedHostAddressResolver($addresses);
        self::getContainer()->set(HostAddressResolverInterface::class, $this->resolver);
    }

    /** Le résolveur du test : par défaut, `claude.ai` est une adresse publique. */
    private function resolver(): FixedHostAddressResolver
    {
        $resolver = $this->resolver ?? new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]);
        if (null === $this->resolver) {
            $this->resolver = $resolver;
            self::getContainer()->set(HostAddressResolverInterface::class, $resolver);
        }

        return $resolver;
    }

    /** Un document JSON tel qu'un hôte le sert. */
    private static function document(string $body): MockResponse
    {
        return new MockResponse($body, ['response_headers' => ['Content-Type: application/json']]);
    }

    /**
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    private function cimdParameters(array $overrides = []): array
    {
        return $this->validParameters(['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => self::CIMD_REDIRECT, ...$overrides]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function authorize(array $parameters, string $extraQuery = ''): Response
    {
        $query = http_build_query($parameters).('' === $extraQuery ? '' : '&'.$extraQuery);
        $this->resolver();
        $this->client->request('GET', self::AUTHORIZE_PATH.'?'.$query, server: ['HTTP_ACCEPT_LANGUAGE' => '']);

        return $this->client->getResponse();
    }

    private function assertInvalidClient(Response $response): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'), 'Un client refusé n\'est jamais une redirection.');
        self::assertSame('invalid_client', $this->json($response)['error']);
    }

    /**
     * Une ligne `cimd` laissée par une autorisation antérieure de claude.ai
     * (le domaine a pu sortir de la liste depuis) : ce que league servirait si
     * rien n'arrêtait un `client_id` rogné.
     */
    private function aDiscoveredRowFromAnEarlierAuthorization(): void
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID) ?? throw new LogicException('Identifiant de test illisible.');
        $row = McpClient::discovered(ClientMetadataDocument::fromJson($clientId, ClaudeCodeMetadata::json()), new MockClock('2026-11-01 10:00:00')->now());

        self::getContainer()->get(McpClientRepositoryInterface::class)->saveDiscovered($row);
    }

    /**
     * Une ligne `cimd` dont l'identifiant a été réécrit hors de la forme CIMD :
     * `discovered()` n'accepte qu'un CimdClientId, la réflexion pose ce qu'une
     * écriture à la main aurait laissé en base. La ligne passe ensuite par le
     * vrai dépôt.
     */
    private function aStoredCimdRowWhoseClientIdIs(string $storedClientId): void
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID) ?? throw new LogicException('Identifiant de test illisible.');
        $row = McpClient::discovered(ClientMetadataDocument::fromJson($clientId, ClaudeCodeMetadata::json()), new MockClock('2026-11-01 10:00:00')->now());
        new ReflectionProperty(McpClient::class, 'clientId')->setValue($row, $storedClientId);

        self::getContainer()->get(McpClientRepositoryInterface::class)->saveDiscovered($row);
    }

    private function clearPools(): void
    {
        self::getContainer()->get('cache.mcp_client_metadata')->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
}
