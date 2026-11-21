<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Infrastructure\Cimd\HostAddressResolverInterface;
use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\Support\FixedHostAddressResolver;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Règle D-C au point de jeton (#652, F-f) : une ligne pré-enregistrée qui
 * porte l'identifiant d'un domaine vérifié masque le client CIMD. Le point
 * d'autorisation et le consentement la refusent déjà ; ce qui reste à
 * fermer, c'est la connexion née d'une telle ligne (elle se rafraîchirait)
 * et le code encore en vol (échangeable dix minutes).
 *
 * Le montage : un vrai parcours CIMD sur `claude.ai` (réseau simulé), puis
 * la ligne passe en `preregistered` par SQL entre deux étapes. Les charges
 * chiffrées du code et du jeton de rafraîchissement portent le même
 * client_id : seule la ligne change de nature.
 */
final class MasqueradingPreregisteredRowTokenTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsAllChannelsLog;
    use ReadsSecurityAuditLog;
    use SpeaksMcp;

    private const string HTTP_CLIENT_SERVICE = 'app.mcp.cimd.http_client';
    private const string CIMD_REDIRECT = 'http://localhost:53124/callback';
    private const string REVOKE_PATH = '/api/oauth/revoke';
    private const string FOREIGN_RESOURCE = 'https://other.example.test/api/mcp';

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
        $this->client->disableReboot(); // le journal d'audit se relit sur plusieurs requêtes
        self::getContainer()->get('cache.mcp_client_metadata')->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $served = ClaudeCodeMetadata::json();
        self::getContainer()->set(self::HTTP_CLIENT_SERVICE, new NoPrivateNetworkHttpClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse($served, ['response_headers' => ['Content-Type: application/json']])),
            PublicAddressPolicy::SUBNETS,
        ));
        self::getContainer()->set(HostAddressResolverInterface::class, new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]));
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions($this->mcpRows());
        self::getContainer()->get('cache.mcp_client_metadata')->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testARefreshOfAConnectionBornOfAMasqueradingRowIsRefused(): void
    {
        $tokens = $this->json($this->exchange($this->cimdCode(), $this->cimdOverrides()));
        self::assertIsString($tokens['refresh_token']);
        $this->masquerade();

        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError($this->refresh($tokens['refresh_token'], $this->cimdClient()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame($tokensBefore, $this->tokenRowCounts());
    }

    public function testACodeIssuedForAMasqueradingRowIsNotExchanged(): void
    {
        $code = $this->cimdCode();
        $this->masquerade();

        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError($this->exchange($code, $this->cimdOverrides()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame($tokensBefore, $this->tokenRowCounts());
    }

    public function testACimdClientIsStillServed(): void
    {
        $tokens = $this->json($this->exchange($this->cimdCode(), $this->cimdOverrides()));
        self::assertIsString($tokens['refresh_token']);

        self::assertSame(Response::HTTP_OK, $this->refresh($tokens['refresh_token'], $this->cimdClient())->getStatusCode());
    }

    public function testAPreregisteredClientOutsideAVerifiedDomainIsStillServed(): void
    {
        $tokens = $this->json($this->exchange($this->grantedCode()));
        self::assertIsString($tokens['refresh_token']);

        self::assertSame(Response::HTTP_OK, $this->refresh($tokens['refresh_token'])->getStatusCode());
    }

    /**
     * Mesure (a) : la révocation passe par le même getClientEntity().
     */
    public function testTheRevocationOfAMasqueradingRowIsRefusedWithoutRevoking(): void
    {
        $tokens = $this->json($this->exchange($this->cimdCode(), $this->cimdOverrides()));
        self::assertIsString($tokens['refresh_token']);
        $this->masquerade();

        $tokensBefore = $this->tokenRowCounts();
        $response = $this->postForm(self::REVOKE_PATH, ['token' => $tokens['refresh_token'], 'client_id' => ClaudeCodeMetadata::CLIENT_ID]);

        $this->assertOAuthError($response, Response::HTTP_UNAUTHORIZED, 'invalid_client');
        self::assertSame($tokensBefore, $this->tokenRowCounts());
    }

    /**
     * Le refus au point de jeton laisse une ligne mcp-grant-refused, une
     * seule, de même forme que les autres refus d'un artefact authentique.
     */
    public function testTheCodeExchangeOfAMasqueradingRowIsAudited(): void
    {
        $code = $this->cimdCode();
        $this->masquerade();

        $this->assertOAuthError($this->exchange($code, $this->cimdOverrides()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        $this->assertAuditedOnce('authorization_code', $code);
    }

    public function testTheRefreshOfAMasqueradingRowIsAudited(): void
    {
        $tokens = $this->json($this->exchange($this->cimdCode(), $this->cimdOverrides()));
        self::assertIsString($tokens['refresh_token']);
        $this->masquerade();

        $this->assertOAuthError($this->refresh($tokens['refresh_token'], $this->cimdClient()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        $this->assertAuditedOnce('refresh_token', $tokens['refresh_token']);
    }

    /**
     * Une ligne masquante n'est pas un client connu, y compris pour le
     * contrôle de la ressource : `invalid_client` (comme ClientRepository),
     * pas `invalid_target` — sinon le refus d'un client déjà jugé inexistant
     * échapperait à la cause dédiée de l'audit.
     */
    public function testTheCodeExchangeOfAMasqueradingRowWithAForeignResourceIsRefusedAsInvalidClientAndAudited(): void
    {
        $code = $this->cimdCode();
        $this->masquerade();

        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError(
            $this->exchange($code, [...$this->cimdOverrides(), 'resource' => self::FOREIGN_RESOURCE]),
            Response::HTTP_UNAUTHORIZED,
            'invalid_client',
        );

        self::assertSame($tokensBefore, $this->tokenRowCounts());
        $this->assertAuditedOnce('authorization_code', $code);
    }

    public function testTheRefreshOfAMasqueradingRowWithAForeignResourceIsRefusedAsInvalidClientAndAudited(): void
    {
        $tokens = $this->json($this->exchange($this->cimdCode(), $this->cimdOverrides()));
        self::assertIsString($tokens['refresh_token']);
        $this->masquerade();

        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError(
            $this->refresh($tokens['refresh_token'], [...$this->cimdClient(), 'resource' => self::FOREIGN_RESOURCE]),
            Response::HTTP_UNAUTHORIZED,
            'invalid_client',
        );

        self::assertSame($tokensBefore, $this->tokenRowCounts());
        $this->assertAuditedOnce('refresh_token', $tokens['refresh_token']);
    }

    /**
     * Un client CIMD ordinaire (non masqué) garde son `invalid_target` : seule
     * la ligne masquante change de verdict.
     */
    public function testACimdClientWithAForeignResourceStillGetsInvalidTarget(): void
    {
        $code = $this->cimdCode();

        $this->assertOAuthError(
            $this->exchange($code, [...$this->cimdOverrides(), 'resource' => self::FOREIGN_RESOURCE]),
            Response::HTTP_BAD_REQUEST,
            'invalid_target',
        );
    }

    /**
     * Un identifiant inconnu ne désigne aucune ligne masquante : pas de ligne.
     */
    public function testAnUnknownClientIsNotAudited(): void
    {
        $code = $this->cimdCode();
        $this->masquerade();

        $this->assertOAuthError($this->exchange($code, ['client_id' => 'https://claude.ai/unknown.json', 'redirect_uri' => self::CIMD_REDIRECT]), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame([], self::securityAuditEvents('mcp-grant-refused'));
    }

    /**
     * Un `client_id` qui n'est pas de la forme du domaine (octets non UTF-8)
     * présenté avec un code authentique : league refuse `invalid_client`, et
     * l'audit tranche en mémoire, sans envoyer la valeur à PostgreSQL, qui la
     * rejetterait — la panne sortait en `critical` (« Grant refusal not
     * audited. ») à volonté, pour qui détient un vrai code.
     */
    public function testAMalformedClientIdWithAnAuthenticCodeIsRefusedWithoutAnAuditFailure(): void
    {
        $code = $this->grantedCode();
        $this->aMasqueradingRow();

        $this->assertOAuthError($this->exchange($code, ['client_id' => "\xFF\xFE"]), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame([], self::securityAuditEvents('mcp-grant-refused'));
        $failures = array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => 'Grant refusal not audited.' === $record->message,
        );
        self::assertSame([], array_values($failures), 'Un client_id mal formé a atteint PostgreSQL : l\'audit du refus est tombé en panne.');
    }

    /**
     * Le client masquant mais un code qui n'est pas authentique : personne à désigner.
     */
    public function testAForgedCodeIsNotAudited(): void
    {
        $this->cimdCode();
        $this->masquerade();

        $this->assertOAuthError($this->exchange('forged-code', $this->cimdOverrides()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame([], self::securityAuditEvents('mcp-grant-refused'));
    }

    /**
     * Client croisé : un code authentique émis pour le client A (hors domaine
     * vérifié), présenté avec le `client_id` d'une ligne masquante B. Le
     * comportement actuel est figé : refus 401 `invalid_client`, rien écrit,
     * une seule ligne d'audit qui désigne A (la connexion) et B (le présentant).
     */
    public function testACodeOfAnotherClientPresentedByAMasqueradingRowIsRefusedAndAuditedWithBothClients(): void
    {
        $code = $this->grantedCode();
        $this->aMasqueradingRow();

        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError($this->exchange($code, $this->cimdClient()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame($tokensBefore, $this->tokenRowCounts());
        $this->assertAuditedAsCrossed('authorization_code', $code);
    }

    public function testARefreshTokenOfAnotherClientPresentedByAMasqueradingRowIsRefusedAndAuditedWithBothClients(): void
    {
        $tokens = $this->json($this->exchange($this->grantedCode()));
        self::assertIsString($tokens['refresh_token']);
        $this->aMasqueradingRow();

        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError($this->refresh($tokens['refresh_token'], $this->cimdClient()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame($tokensBefore, $this->tokenRowCounts());
        $this->assertAuditedAsCrossed('refresh_token', $tokens['refresh_token']);
    }

    private function aMasqueradingRow(): void
    {
        self::getContainer()->get(McpClientRepositoryInterface::class)->add(
            RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Faux Claude Code', [self::CIMD_REDIRECT]),
        );
    }

    private function assertAuditedAsCrossed(string $grantType, string $presented): void
    {
        $line = self::singleSecurityAuditEvent('mcp-grant-refused');

        self::assertSame('preregistered-row-shadows-verified-domain', $line['cause']);
        self::assertSame($grantType, $line['grantType']);
        self::assertSame(self::CLIENT_ID, $line['clientId'], 'clientId est celui de la connexion (A).');
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $line['presentingClientId'], 'presentingClientId est celui de la ligne masquante (B).');
        self::assertStringNotContainsString($presented, (string) json_encode($line));
    }

    private function assertAuditedOnce(string $grantType, string $presented): void
    {
        $line = self::singleSecurityAuditEvent('mcp-grant-refused');

        self::assertEqualsCanonicalizing(['event', 'userId', 'connectionId', 'clientId', 'cause', 'grantType', 'presentingClientId', 'actor', 'ip', 'path'], array_keys($line));
        self::assertSame('preregistered-row-shadows-verified-domain', $line['cause']);
        self::assertSame($grantType, $line['grantType']);
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $line['clientId']);
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $line['presentingClientId']);
        self::assertStringNotContainsString($presented, (string) json_encode($line));
    }

    /**
     * Mesure (c) : un jeton d'accès déjà émis reste accepté sur /api/mcp,
     * l'authentificateur ne juge ni le mode d'enregistrement ni le domaine.
     */
    public function testAnAccessTokenAlreadyIssuedStaysAcceptedAtTheMcpEndpoint(): void
    {
        $held = $this->heldMcpConnectionOf($this->exchange($this->cimdCode(), $this->cimdOverrides()));
        $this->masquerade();

        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        self::assertNotSame('', $sessionId);
    }

    /**
     * @return array<string, string>
     */
    private function cimdOverrides(): array
    {
        return ['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => self::CIMD_REDIRECT];
    }

    /**
     * @return array<string, string>
     */
    private function cimdClient(): array
    {
        return ['client_id' => ClaudeCodeMetadata::CLIENT_ID];
    }

    /**
     * Un code émis par le vrai parcours CIMD : résumé, puis approbation.
     */
    private function cimdCode(): string
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $parameters = $this->validParameters($this->cimdOverrides());

        $this->client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($parameters));
        $redirect = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $redirect->getStatusCode(), substr((string) $redirect->getContent(), 0, 300));
        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($this->queryOf((string) $redirect->headers->get('Location'))));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $received = $this->queryOf($this->redirectUriOf($this->decide(approved: true, overrides: $this->cimdOverrides())));
        self::assertIsString($received['code']);

        return $received['code'];
    }

    /**
     * La ligne du client CIMD devient une ligne pré-enregistrée : celle d'un
     * administrateur qui aurait saisi l'identifiant d'un domaine vérifié.
     */
    private function masquerade(): void
    {
        $changed = $this->mcpRows()->executeStatement(
            "UPDATE mcp_client SET registration = 'preregistered' WHERE client_id = :clientId",
            ['clientId' => ClaudeCodeMetadata::CLIENT_ID],
        );
        self::assertSame(1, $changed);
    }

    /**
     * @return array<string, int>
     */
    private function tokenRowCounts(): array
    {
        return [
            'access' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token'),
            'access révoqués' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NOT NULL'),
            'refresh' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token'),
            'refresh révoqués' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NOT NULL'),
        ];
    }
}
