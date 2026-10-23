<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Infrastructure\League\ConnectionAsUser;
use App\Mcp\Connection\Infrastructure\League\NominativeReadScope;
use App\Security\User\Domain\Entity\CpgUser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le serveur d'autorisation tel que le conteneur le construit (#647) : la
 * redirection jugée par McpClient::allowsRedirect() — league seul ne tient pas
 * `localhost` pour la boucle locale —, puis un échange complet (code, jeton,
 * rafraîchissement) qui ne touche jamais la clé de signature : les jetons
 * d'accès sont opaques.
 */
final class AuthorizationServerTest extends KernelTestCase
{
    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    /** Un vérificateur PKCE conforme à RFC 7636 (43 caractères au moins). */
    private const string CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-test';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->purge();
        $this->registerClient();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * Un client natif écoute sur un port éphémère qu'il n'a pas pu annoncer à
     * l'enregistrement (RFC 8252 §7.3) : `http://localhost/callback`
     * enregistrée admet ce chemin sur tout port.
     */
    #[DataProvider('loopbackPorts')]
    public function testTheGrantAcceptsALocalhostRedirectOnAnyPort(string $redirectUri): void
    {
        $request = $this->server()->validateAuthorizationRequest($this->authorizationRequest($redirectUri));

        self::assertSame($redirectUri, $request->getRedirectUri());
        self::assertSame(self::CLIENT_ID, $request->getClient()->getIdentifier());
        self::assertSame([NominativeReadScope::IDENTIFIER], $this->scopeIdentifiers($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackPorts(): iterable
    {
        yield 'sans port' => ['http://localhost/callback'];
        yield 'port 1' => ['http://localhost:1/callback'];
        yield 'port éphémère' => ['http://localhost:49152/callback'];
        yield 'port 65535' => ['http://localhost:65535/callback'];
    }

    /**
     * Le port seul est libre : un autre chemin, un autre hôte de boucle locale
     * que celui enregistré, ou une redirection https qui n'est pas exactement
     * l'enregistrée sont refusés comme league le fait — `invalid_client`,
     * l'échec d'authentification émis.
     */
    #[DataProvider('refusedRedirects')]
    public function testTheGrantRefusesAnotherLoopbackPath(string $redirectUri): void
    {
        $server = $this->server();
        $failures = 0;
        $server->getEmitter()->subscribeTo(RequestEvent::CLIENT_AUTHENTICATION_FAILED, static function () use (&$failures): void {
            ++$failures;
        });

        try {
            $server->validateAuthorizationRequest($this->authorizationRequest($redirectUri));
            self::fail('La redirection aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
        }

        self::assertSame(1, $failures);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedRedirects(): iterable
    {
        yield 'autre chemin' => ['http://localhost:49152/other'];
        yield 'barre finale' => ['http://localhost:49152/callback/'];
        yield 'autre hôte de boucle locale' => ['http://127.0.0.1:49152/callback'];
        yield 'https avec un port' => ['https://client.example.test:443/callback'];
        yield 'https avec une barre finale' => ['https://client.example.test/callback/'];
        yield 'https avec une requête' => ['https://client.example.test/callback?x=1'];
        yield 'https en http' => ['http://client.example.test/callback'];
    }

    public function testAnHttpsRedirectIsAcceptedOnExactMatch(): void
    {
        $request = $this->server()->validateAuthorizationRequest($this->authorizationRequest('https://client.example.test/callback'));

        self::assertSame('https://client.example.test/callback', $request->getRedirectUri());
    }

    /**
     * Le code, le jeton et le rafraîchissement, de bout en bout : la réponse
     * porte un jeton d'accès opaque (80 caractères hexadécimaux, l'identifiant
     * que league tire), la base n'en garde que l'empreinte, et le
     * rafraîchissement révoque l'ancien jeton d'accès, l'ancien
     * rafraîchissement et — dès l'échange — le code. NoSigningKey lèverait au
     * moindre usage de la clé : la traversée sans exception le prouve.
     */
    public function testAFullAuthorizationCodeFlowNeverTouchesTheSigningKey(): void
    {
        $server = $this->server();
        $connection = $this->grantConnection();
        $redirectUri = 'http://localhost:49152/callback';

        $authorization = $server->validateAuthorizationRequest($this->authorizationRequest($redirectUri));
        $authorization->setUser(ConnectionAsUser::of($connection));
        $authorization->setAuthorizationApproved(true);
        $redirect = $server->completeAuthorizationRequest($authorization, new Response());

        $location = $redirect->getHeaderLine('Location');
        self::assertStringStartsWith($redirectUri.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('state-1', $query['state'] ?? null);
        $code = $query['code'] ?? null;
        self::assertIsString($code);
        self::assertSame(1, $this->rowCount('mcp_authorization_code'));

        $tokens = $this->decode($server->respondToAccessTokenRequest($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => self::CODE_VERIFIER,
        ]), new Response()));

        self::assertSame('Bearer', $tokens['token_type']);
        self::assertIsString($tokens['access_token']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{80}\z/', $tokens['access_token']);
        self::assertIsInt($tokens['expires_in']);
        self::assertEqualsWithDelta(3600, $tokens['expires_in'], 5);
        self::assertIsString($tokens['refresh_token']);
        $access = $this->em->getConnection()->fetchAssociative('SELECT token_hash, audience FROM mcp_access_token');
        self::assertSame(['token_hash' => hash('sha256', $tokens['access_token']), 'audience' => $this->endpoints()->resource()], $access);
        self::assertSame(1, $this->revokedCount('mcp_authorization_code'), 'Le code échangé doit être révoqué.');

        $refreshed = $this->decode($server->respondToAccessTokenRequest($this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]), new Response()));

        self::assertIsString($refreshed['access_token']);
        self::assertNotSame($tokens['access_token'], $refreshed['access_token']);
        self::assertSame(2, $this->rowCount('mcp_access_token'));
        self::assertSame(1, $this->revokedCount('mcp_access_token'), 'L\'ancien jeton d\'accès doit être révoqué.');
        self::assertSame(1, $this->revokedCount('mcp_refresh_token'), 'L\'ancien rafraîchissement doit être révoqué.');
        self::assertSame(2, $this->rowCount('mcp_refresh_token'));
    }

    /**
     * Le jeton de rafraîchissement d'une connexion révoquée ne rafraîchit
     * plus : league demande « révoqué ? », la connexion répond.
     */
    public function testARefreshOnARevokedConnectionIsRefused(): void
    {
        $server = $this->server();
        $connection = $this->grantConnection();
        $authorization = $server->validateAuthorizationRequest($this->authorizationRequest('https://client.example.test/callback'));
        $authorization->setUser(ConnectionAsUser::of($connection));
        $authorization->setAuthorizationApproved(true);
        parse_str((string) parse_url($server->completeAuthorizationRequest($authorization, new Response())->getHeaderLine('Location'), \PHP_URL_QUERY), $query);
        $code = $query['code'] ?? null;
        self::assertIsString($code);
        $tokens = $this->decode($server->respondToAccessTokenRequest($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'redirect_uri' => 'https://client.example.test/callback',
            'code_verifier' => self::CODE_VERIFIER,
        ]), new Response()));
        $refreshToken = $tokens['refresh_token'] ?? null;
        self::assertIsString($refreshToken);
        // Révoquée par une autre requête : l'EntityManager de celle-ci, qui a
        // chargé la connexion, est vidé comme le serait celui d'une requête neuve.
        $this->em->getConnection()->executeStatement('UPDATE mcp_connection SET revoked_at = NOW()');
        $this->em->clear();

        try {
            $server->respondToAccessTokenRequest($this->tokenRequest([
                'grant_type' => 'refresh_token',
                'client_id' => self::CLIENT_ID,
                'refresh_token' => $refreshToken,
            ]), new Response());
            self::fail('Le rafraîchissement aurait dû être refusé.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_grant', $exception->getErrorType());
        }
    }

    private function server(): AuthorizationServer
    {
        return self::getContainer()->get(AuthorizationServer::class);
    }

    private function endpoints(): OAuthEndpoints
    {
        $container = self::getContainer();
        $publicApi = $container->getParameter('app.public_api_base_url');
        $frontend = $container->getParameter('app.frontend_base_url');
        self::assertIsString($publicApi);

        return new OAuthEndpoints($publicApi, $frontend);
    }

    private function authorizationRequest(string $redirectUri): ServerRequest
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'), '=');

        return new ServerRequest('GET', 'http://localhost/api/oauth/authorize')->withQueryParams([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'state' => 'state-1',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @param array<string, string> $body
     */
    private function tokenRequest(array $body): ServerRequest
    {
        return new ServerRequest('POST', 'http://localhost/api/oauth/token')->withParsedBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function scopeIdentifiers(AuthorizationRequestInterface $request): array
    {
        $identifiers = [];
        foreach ($request->getScopes() as $scope) {
            $identifiers[] = $scope->getIdentifier();
        }

        return $identifiers;
    }

    private function registerClient(): void
    {
        self::getContainer()->get(McpClientRepositoryInterface::class)->add(
            McpClient::register(self::CLIENT_ID, 'Client de test', ['http://localhost/callback', 'https://client.example.test/callback'], new DateTimeImmutable()),
        );
    }

    private function grantConnection(): McpConnection
    {
        $account = new CpgUser('jane', 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();
        $client = self::getContainer()->get(McpClientRepositoryInterface::class)->findOneByClientId(self::CLIENT_ID);
        self::assertInstanceOf(McpClient::class, $client);
        $connection = McpConnection::grant($account, $client, NominativeReadScope::IDENTIFIER, new DateTimeImmutable());
        self::getContainer()->get(McpConnectionRepositoryInterface::class)->add($connection);

        return $connection;
    }

    private function rowCount(string $table): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }

    private function revokedCount(string $table): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE revoked_at IS NOT NULL');
        self::assertIsInt($count);

        return $count;
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
