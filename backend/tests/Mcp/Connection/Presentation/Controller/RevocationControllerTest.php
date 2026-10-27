<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Infrastructure\League\AccessTokenRepository;
use App\Mcp\Connection\Infrastructure\League\ConnectionOfToken;
use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestKeys;
use Defuse\Crypto\Crypto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le point de révocation OAuth de la connexion MCP (#647, RFC 7009),
 * `POST /api/oauth/revoke`, de bout en bout par HTTP.
 *
 * - Un jeton d'accès révoque sa ligne, et elle seule : la connexion reste
 *   active. Un jeton de rafraîchissement, lu sous la clé dérivée de league,
 *   révoque la sienne, le jeton d'accès émis avec lui **et sa connexion**
 *   (#649, RFC 7009 §2.1), cause `client`, auditée une fois.
 * - Un jeton inconnu, illisible ou émis à un autre client : 200 sans effet
 *   (RFC 7009 §2.2), la même réponse qu'un succès — rien ne dit à l'appelant
 *   qu'un jeton existe.
 * - `token` manquant : 400 `invalid_request` ; `client_id` manquant ou
 *   inconnu : 401 `invalid_client`. Le JSON de RFC 6749, jamais un
 *   problem+json.
 */
final class RevocationControllerTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsSecurityAuditLog;

    private const string REVOKE_PATH = '/api/oauth/revoke';

    private const string REVOKED_EVENT = 'mcp-connection-revoked';

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testRevokingAnAccessTokenRevokesItsRowOnly(): void
    {
        $tokens = $this->issuedTokens();

        $response = $this->revoke(['token' => $tokens['access_token'], 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($tokens['access_token']));
        self::assertFalse($this->refreshTokenRowIsRevoked($tokens['refresh_token']));
        // RFC 7009 §2.1 permet seulement de couper le reste : le client garde
        // sa connexion, et son rafraîchissement lui obtiendra un autre jeton.
        $this->assertConnectionActive();
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /**
     * Le jeton de rafraîchissement et son jeton d'accès révoqués tous deux, et
     * la connexion avec eux (#649, décision 3 : RFC 7009 §2.1, un
     * rafraîchissement rendu révoque l'autorisation qu'il porte), cause
     * `client`, une ligne d'audit lue sur la requête qui l'écrit — le compte
     * par son UUID, la connexion, le client public, jamais un jeton.
     *
     * Le rafraîchissement révoqué qui revient ensuite est un `invalid_grant`
     * sur une connexion déjà révoquée : ni seconde ligne, ni changement de
     * cause — ce n'est pas une réutilisation, que seul un jeton tourné sur une
     * connexion encore active peut être.
     */
    public function testRevokingARefreshTokenRevokesItsConnection(): void
    {
        $tokens = $this->issuedTokens();
        [$connectionId, $accountId] = $this->theConnection();

        $response = $this->revoke(['token' => $tokens['refresh_token'], 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame($accountId, $event['userId'] ?? null);
        self::assertSame($connectionId, $event['connectionId'] ?? null);
        self::assertSame(self::CLIENT_ID, $event['clientId'] ?? null);
        self::assertSame('client', $event['cause'] ?? null);
        $context = json_encode($event, \JSON_THROW_ON_ERROR);
        foreach ($tokens as $secret) {
            // Ni l'aiguille ni la meule dans le message d'échec : un échec
            // publierait le secret dans le journal de la CI.
            self::assertFalse(str_contains($context, $secret), 'La ligne d\'audit cite un jeton.');
        }

        self::assertTrue($this->refreshTokenRowIsRevoked($tokens['refresh_token']));
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($tokens['access_token']));
        self::assertSame('client', $this->revocationCause());
        $revokedAt = $this->revokedAtEpoch();

        $this->assertOAuthError($this->refresh($tokens['refresh_token']), Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertSame('client', $this->revocationCause());
        self::assertSame($revokedAt, $this->revokedAtEpoch());
    }

    /**
     * Un rafraîchissement **déjà tourné** rendu au point de révocation, sa
     * connexion encore active : le même signal qu'une réutilisation au point
     * de jeton (décision 3 — sur une connexion active, un rafraîchissement
     * révoqué ne peut venir que d'une rotation). La connexion est révoquée
     * pour réutilisation, cause `refresh-token-reuse`, une ligne d'audit ; la
     * réponse reste le 200 de RFC 7009 §2.2, qui ne dit rien de plus.
     */
    public function testRevokingAnAlreadyRotatedRefreshTokenIsAuditedAsReuse(): void
    {
        $tokens = $this->issuedTokens();
        [$connectionId] = $this->theConnection();
        $refreshed = $this->refresh($tokens['refresh_token']);
        self::assertSame(Response::HTTP_OK, $refreshed->getStatusCode(), substr((string) $refreshed->getContent(), 0, 500));
        $this->assertConnectionActive();

        $response = $this->revoke(['token' => $tokens['refresh_token'], 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame($connectionId, $event['connectionId'] ?? null);
        self::assertSame('refresh-token-reuse', $event['cause'] ?? null);
        self::assertSame('refresh-token-reuse', $this->revocationCause());
    }

    /** Le `token_type_hint` est lu comme une indication, jamais comme une règle (RFC 7009 §2.1). */
    public function testAWrongTokenTypeHintStillRevokesTheToken(): void
    {
        $tokens = $this->issuedTokens();

        $response = $this->revoke(['token' => $tokens['access_token'], 'token_type_hint' => 'refresh_token', 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($tokens['access_token']));
    }

    /**
     * Un jeton émis à un autre client : 200, et rien de révoqué — la réponse
     * ne dit pas qu'il existe.
     *
     * @return iterable<string, array{'access_token'|'refresh_token'}>
     */
    public static function tokenKinds(): iterable
    {
        yield 'jeton d\'accès' => ['access_token'];
        yield 'jeton de rafraîchissement' => ['refresh_token'];
    }

    /**
     * @param 'access_token'|'refresh_token' $kind
     */
    #[DataProvider('tokenKinds')]
    public function testATokenIssuedToAnotherClientIsLeftAlone(string $kind): void
    {
        $tokens = $this->issuedTokens();

        $response = $this->revoke(['token' => $tokens[$kind], 'client_id' => self::OTHER_CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        $this->assertNothingRevoked();
        $this->assertConnectionActive();
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /**
     * Inconnu ou illisible : la même réponse qu'un succès, sans effet.
     *
     * @return iterable<string, array{string}>
     */
    public static function unknownTokens(): iterable
    {
        yield 'hexadécimal de la forme d\'un jeton d\'accès' => [bin2hex(random_bytes(40))];
        yield 'texte quelconque' => ['pas-un-jeton'];
        yield 'chiffré de la forme de defuse, illisible' => ['def50200'.bin2hex(random_bytes(120))];
    }

    #[DataProvider('unknownTokens')]
    public function testAnUnknownOrUnreadableTokenIsAcceptedWithoutEffect(string $token): void
    {
        $this->issuedTokens();

        $response = $this->revoke(['token' => $token, 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        $this->assertNothingRevoked();
    }

    /**
     * Un rafraîchissement chiffré sous une autre clé que celle de league
     * (dérivée d'`APP_SECRET`) n'est pas lisible, même s'il nomme un vrai
     * jeton : 200 sans effet.
     */
    public function testARefreshTokenEncryptedUnderAnotherKeyIsLeftAlone(): void
    {
        $tokens = $this->issuedTokens();
        $payload = Crypto::decrypt($tokens['refresh_token'], $this->encryptionKey());
        $forged = Crypto::encrypt($payload, OAuthEncryptionKey::fromSecret(TestKeys::material('autre-cle-647')));

        $response = $this->revoke(['token' => $forged, 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        $this->assertNothingRevoked();
    }

    /** @return iterable<string, array{array<string, string|null>}> */
    public static function requestsWithoutToken(): iterable
    {
        yield 'absent' => [['token' => null]];
        yield 'vide' => [['token' => '']];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('requestsWithoutToken')]
    public function testARequestWithoutTokenIsInvalidRequest(array $overrides): void
    {
        $this->issuedTokens();

        $response = $this->revoke(['token' => 'pas-un-jeton', 'client_id' => self::CLIENT_ID, ...$overrides]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
    }

    /** @return iterable<string, array{string|null}> */
    public static function clientsNotRegistered(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'inconnu' => ['https://unknown.example.test/metadata.json'];
    }

    #[DataProvider('clientsNotRegistered')]
    public function testARequestWithoutARegisteredClientIsInvalidClient(?string $clientId): void
    {
        $tokens = $this->issuedTokens();

        $response = $this->revoke(['token' => $tokens['access_token'], 'client_id' => $clientId]);

        $this->assertOAuthError($response, Response::HTTP_UNAUTHORIZED, 'invalid_client');
        $this->assertNothingRevoked();
    }

    /**
     * Le client est authentifié avant que le jeton ne soit lu (RFC 7009
     * §2.1) : sans client enregistré, `invalid_client`, que le jeton manque
     * ou non.
     */
    #[DataProvider('clientsNotRegistered')]
    public function testTheClientIsAuthenticatedBeforeTheTokenIsRead(?string $clientId): void
    {
        $response = $this->revoke(['client_id' => $clientId]);

        $this->assertOAuthError($response, Response::HTTP_UNAUTHORIZED, 'invalid_client');
    }

    /** Un corps JSON n'est pas un formulaire (RFC 7009 §2.1) : refusé, rien de révoqué. */
    public function testAJsonBodyIsInvalidRequest(): void
    {
        $tokens = $this->issuedTokens();

        $this->client->request('POST', self::REVOKE_PATH, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: self::jsonBody([
            'token' => $tokens['access_token'],
            'client_id' => self::CLIENT_ID,
        ]));

        $this->assertOAuthError($this->client->getResponse(), Response::HTTP_BAD_REQUEST, 'invalid_request');
        $this->assertNothingRevoked();
    }

    /**
     * Une autre méthode que POST : le 405 du routeur, avant le contrôleur, au
     * format commun des erreurs sous `/api` (ApiJsonErrorFormatListener, un
     * problem+json) plutôt qu'OAuth — accepté, comme au point de jeton ;
     * jamais une page HTML.
     */
    public function testAGetIsA405InJson(): void
    {
        $this->client->request('GET', self::REVOKE_PATH);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertStringContainsString('json', (string) $response->headers->get('Content-Type'));
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->json($response)['status'] ?? null);
    }

    /**
     * Un paramètre répété : refusé, jamais lu d'une façon ou de l'autre.
     *
     * @return iterable<string, array{string}>
     */
    public static function repeatableParameters(): iterable
    {
        yield 'token' => ['token'];
        yield 'client_id' => ['client_id'];
    }

    #[DataProvider('repeatableParameters')]
    public function testARepeatedParameterIsInvalidRequest(string $name): void
    {
        $tokens = $this->issuedTokens();
        $parameters = ['token' => $tokens['access_token'], 'client_id' => self::CLIENT_ID];

        $response = $this->postForm(self::REVOKE_PATH, http_build_query($parameters).'&'.http_build_query([$name => $parameters[$name]]));

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
        $this->assertNothingRevoked();
    }

    /**
     * Exclu de la garde CSRF parce que son firewall ne lit aucun cookie : le
     * `BEARER` d'une personne de confiance, sans en-tête XSRF, n'y vaut ni un
     * 403 ni une identité.
     */
    public function testABearerCookieWithoutTheXsrfHeaderIsNeitherRefusedNorRead(): void
    {
        $tokens = $this->issuedTokens();
        self::assertNotNull($this->client->getCookieJar()->get('BEARER'));

        $response = $this->revoke(['token' => $tokens['access_token'], 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        self::assertSame([], self::securityAuditEvents('csrf-rejected'));
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($tokens['access_token']));
    }

    /**
     * Un jeton d'accès et un jeton de rafraîchissement émis à CLIENT_ID par le
     * parcours complet. Le compte de confiance reste connecté (cookies).
     *
     * @return array{access_token: string, refresh_token: string}
     */
    private function issuedTokens(): array
    {
        $response = $this->exchange($this->grantedCode());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $body = $this->json($response);
        self::assertIsString($body['access_token'] ?? null);
        self::assertIsString($body['refresh_token'] ?? null);

        return ['access_token' => $body['access_token'], 'refresh_token' => $body['refresh_token']];
    }

    /**
     * @param array<string, string|null> $parameters
     */
    private function revoke(array $parameters): Response
    {
        return $this->postForm(self::REVOKE_PATH, $parameters);
    }

    /** RFC 7009 §2.2 : 200, corps vide, jamais en cache. */
    private function assertRevocationAccepted(Response $response): void
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('', $response->getContent());
        $this->assertNotStored($response);
    }

    private function assertNothingRevoked(): void
    {
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NOT NULL'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NOT NULL'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));
    }

    /**
     * L'identifiant de la seule connexion du test et celui de son compte,
     * en RFC 4122, tels que la ligne d'audit les écrit.
     *
     * @return array{string, string}
     */
    private function theConnection(): array
    {
        $rows = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAllNumeric('SELECT id, account_id FROM mcp_connection');
        self::assertCount(1, $rows);
        [$connectionId, $accountId] = $rows[0];
        self::assertIsString($connectionId);
        self::assertIsString($accountId);

        return [$connectionId, $accountId];
    }

    /** La seule connexion du test est active : ni instant, ni cause de révocation. */
    private function assertConnectionActive(): void
    {
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NULL AND revocation_cause IS NULL'));
    }

    private function revocationCause(): ?string
    {
        $cause = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT revocation_cause FROM mcp_connection');
        self::assertTrue(null === $cause || \is_string($cause));

        return $cause;
    }

    /** L'instant de révocation de la seule connexion, en secondes : la première révocation le garde. */
    private function revokedAtEpoch(): ?int
    {
        $epoch = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT EXTRACT(EPOCH FROM revoked_at)::bigint FROM mcp_connection');
        self::assertTrue(null === $epoch || \is_int($epoch));

        return $epoch;
    }

    /** L'identifiant que la charge chiffrée du jeton de rafraîchissement porte. */
    private function refreshTokenIdentifier(string $refreshToken): string
    {
        $identifier = $this->payloadOf($refreshToken)['refresh_token_id'] ?? null;
        self::assertIsString($identifier);

        return $identifier;
    }

    /**
     * Le dépôt des jetons d'accès de league, tel que le serveur le lit, sur un
     * EntityManager vidé : une révocation écrite en DQL par la requête n'est
     * vue qu'après un `clear()`.
     */
    private function accessTokens(): AccessTokenRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return new AccessTokenRepository(
            self::getContainer()->get(McpAccessTokenRepositoryInterface::class),
            new ConnectionOfToken(self::getContainer()->get(McpConnectionRepositoryInterface::class)),
            self::getContainer()->get(OAuthEndpoints::class),
            self::getContainer()->get(ClockInterface::class),
        );
    }

    /**
     * La ligne du rafraîchissement, par l'empreinte de l'identifiant que sa
     * charge porte : révoquée ou non. Lue en SQL plutôt que par le dépôt de
     * league, dont le jugement (#649) se fait sous le verrou de la connexion
     * et lit un rafraîchissement révoqué sur une connexion active comme une
     * réutilisation.
     */
    private function refreshTokenRowIsRevoked(string $refreshToken): bool
    {
        return 1 === $this->countRows(
            'SELECT COUNT(*) FROM mcp_refresh_token WHERE token_hash = :hash AND revoked_at IS NOT NULL',
            ['hash' => hash('sha256', $this->refreshTokenIdentifier($refreshToken))],
        );
    }
}
