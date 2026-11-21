<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Infrastructure\Cimd\HostAddressResolverInterface;
use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\Support\FixedHostAddressResolver;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsSecurityAuditLog;
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
    use ReadsSecurityAuditLog;
    use SpeaksMcp;

    private const string HTTP_CLIENT_SERVICE = 'app.mcp.cimd.http_client';
    private const string CIMD_REDIRECT = 'http://localhost:53124/callback';
    private const string REVOKE_PATH = '/api/oauth/revoke';

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
     * Mesure (b) : le refus au point de jeton laisse-t-il une ligne mcp-grant-refused ?
     */
    public function testTheTokenRefusalOfAMasqueradingRowLeavesNoGrantRefusedLine(): void
    {
        $code = $this->cimdCode();
        $this->masquerade();

        $this->assertOAuthError($this->exchange($code, $this->cimdOverrides()), Response::HTTP_UNAUTHORIZED, 'invalid_client');

        self::assertSame([], self::securityAuditEvents('mcp-grant-refused'));
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
