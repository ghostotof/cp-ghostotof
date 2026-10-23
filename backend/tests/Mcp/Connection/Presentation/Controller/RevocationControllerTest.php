<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Infrastructure\League\AccessTokenRepository;
use App\Mcp\Connection\Infrastructure\League\ConnectionOfToken;
use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Mcp\Connection\Infrastructure\League\RefreshTokenRepository;
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
 * - Un jeton d'accès révoque sa ligne ; un jeton de rafraîchissement, lu
 *   sous la clé dérivée de league, révoque la sienne et le jeton d'accès
 *   émis avec lui.
 * - Un jeton inconnu, illisible ou émis à un autre client : 200 sans effet
 *   (RFC 7009 §2.2), la même réponse qu'un succès — rien ne dit à l'appelant
 *   qu'un jeton existe.
 * - `token` manquant : 400 `invalid_request` ; `client_id` manquant ou
 *   inconnu : 401 `invalid_client`. Le JSON de RFC 6749, jamais un
 *   problem+json.
 * - La connexion elle-même n'est pas révoquée ici (#649, #650).
 */
final class RevocationControllerTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsSecurityAuditLog;

    private const string REVOKE_PATH = '/api/oauth/revoke';

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
        self::assertFalse($this->refreshTokens()->isRefreshTokenRevoked($this->refreshTokenIdentifier($tokens['refresh_token'])));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));
    }

    /**
     * Le jeton de rafraîchissement et son jeton d'accès : révoqués tous deux,
     * et le rafraîchissement refusé ensuite au point de jeton.
     */
    public function testRevokingARefreshTokenRevokesItAndItsAccessToken(): void
    {
        $tokens = $this->issuedTokens();

        $response = $this->revoke(['token' => $tokens['refresh_token'], 'client_id' => self::CLIENT_ID]);

        $this->assertRevocationAccepted($response);
        self::assertTrue($this->refreshTokens()->isRefreshTokenRevoked($this->refreshTokenIdentifier($tokens['refresh_token'])));
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($tokens['access_token']));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));

        $this->assertOAuthError($this->refresh($tokens['refresh_token']), Response::HTTP_BAD_REQUEST, 'invalid_grant');
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

    /** L'identifiant que la charge chiffrée du jeton de rafraîchissement porte. */
    private function refreshTokenIdentifier(string $refreshToken): string
    {
        $identifier = $this->payloadOf($refreshToken)['refresh_token_id'] ?? null;
        self::assertIsString($identifier);

        return $identifier;
    }

    /**
     * Les dépôts de league, tels que le serveur les lit, sur un EntityManager
     * vidé : une révocation écrite en DQL par la requête n'est vue qu'après un
     * `clear()`.
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

    private function refreshTokens(): RefreshTokenRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return new RefreshTokenRepository(
            self::getContainer()->get(McpRefreshTokenRepositoryInterface::class),
            self::getContainer()->get(McpAccessTokenRepositoryInterface::class),
            new ConnectionOfToken(self::getContainer()->get(McpConnectionRepositoryInterface::class)),
            self::getContainer()->get(ClockInterface::class),
        );
    }
}
