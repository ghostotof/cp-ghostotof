<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Infrastructure\League\ConnectionAsUser;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateInterval;
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
        self::assertSame([McpConnection::SCOPE_NOMINATIVE_READ], $this->scopeIdentifiers($request));
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

    /**
     * PKCE `S256` seul (#647) : league admettrait `plain` et lirait une méthode
     * absente comme `plain`. Le refus est un `invalid_request` renvoyé au
     * client sur sa redirection validée, `state` compris, comme league le fait
     * pour un scope invalide.
     *
     * @param array<string, string> $pkce
     */
    #[DataProvider('refusedPkce')]
    public function testAnAuthorizationRequestWithoutS256PkceIsRefusedBackToTheClient(array $pkce, string $parameter): void
    {
        try {
            $this->server()->validateAuthorizationRequest($this->authorizationRequest('http://localhost:49152/callback', $pkce));
            self::fail('La demande aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_request', $exception->getErrorType());
            self::assertStringContainsString('`'.$parameter.'`', (string) $exception->getHint());
            self::assertSame('http://localhost:49152/callback?state=state-1', $exception->getRedirectUri());
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusedPkce(): iterable
    {
        yield 'aucun défi' => [[], 'code_challenge'];
        yield 'méthode sans défi' => [['code_challenge_method' => 'S256'], 'code_challenge'];
        yield 'plain' => [['code_challenge' => self::codeChallenge(), 'code_challenge_method' => 'plain'], 'code_challenge_method'];
        yield 'défi sans méthode (plain pour league)' => [['code_challenge' => self::codeChallenge()], 'code_challenge_method'];
        yield 's256 en minuscules' => [['code_challenge' => self::codeChallenge(), 'code_challenge_method' => 's256'], 'code_challenge_method'];
    }

    /**
     * Un PKCE refusé ne fait jamais rediriger vers une URI que rien n'a
     * validée : une redirection inconnue reste un `invalid_client`, sans
     * redirection, et un client inconnu aussi.
     */
    public function testAPkceRefusalNeverRedirectsToAnUnvalidatedUri(): void
    {
        $plain = ['code_challenge' => self::codeChallenge(), 'code_challenge_method' => 'plain'];

        foreach ([
            $this->authorizationRequest('http://localhost:49152/other', $plain),
            new ServerRequest('GET', 'http://localhost/api/oauth/authorize')->withQueryParams([
                'response_type' => 'code',
                'client_id' => 'https://unknown.example.test/metadata.json',
                'redirect_uri' => 'http://localhost:49152/callback',
                ...$plain,
            ]),
        ] as $request) {
            try {
                $this->server()->validateAuthorizationRequest($request);
                self::fail('La demande aurait dû être refusée.');
            } catch (OAuthServerException $exception) {
                self::assertSame('invalid_client', $exception->getErrorType());
                self::assertFalse($exception->hasRedirect());
            }
        }
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
        self::assertEqualsWithDelta($this->nowPlus('PT10M'), $this->latestExpiry('mcp_authorization_code'), 5, 'Un code vit 10 minutes.');

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
        self::assertEqualsWithDelta($this->nowPlus('PT1H'), $this->latestExpiry('mcp_access_token'), 5, 'Un jeton d\'accès vit une heure.');
        self::assertEqualsWithDelta($this->nowPlus('P30D'), $this->latestExpiry('mcp_refresh_token'), 5, 'Un rafraîchissement émis au code vit 30 jours.');

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
        self::assertIsInt($refreshed['expires_in']);
        self::assertEqualsWithDelta(3600, $refreshed['expires_in'], 5);
        self::assertEqualsWithDelta($this->nowPlus('PT1H'), $this->latestExpiry('mcp_access_token'), 5);
        self::assertEqualsWithDelta($this->nowPlus('P30D'), $this->latestExpiry('mcp_refresh_token'), 5, 'Un rafraîchissement émis au rafraîchissement vit 30 jours.');
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

    /**
     * La définition de la configuration commune, la production : `when@test`
     * n'en déclare qu'un alias public (`test.oauth.authorization_server`), qui
     * garde le service privé en vie pour le conteneur de test, sans le
     * redéfinir.
     */
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

    /**
     * @param array<string, string>|null $pkce les paramètres PKCE, un défi S256 valide par défaut
     */
    private function authorizationRequest(string $redirectUri, ?array $pkce = null): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost/api/oauth/authorize')->withQueryParams([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'state' => 'state-1',
            ...($pkce ?? ['code_challenge' => self::codeChallenge(), 'code_challenge_method' => 'S256']),
        ]);
    }

    /** Le défi S256 de CODE_VERIFIER (RFC 7636 §4.2). */
    private static function codeChallenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'), '=');
    }

    /** L'échéance d'une colonne, en secondes, pour la ligne la plus récente. */
    private function latestExpiry(string $table): int
    {
        $expiry = $this->em->getConnection()->fetchOne('SELECT EXTRACT(EPOCH FROM expires_at)::bigint FROM '.$table.' ORDER BY id DESC LIMIT 1');
        self::assertIsInt($expiry);

        return $expiry;
    }

    /** Maintenant plus une durée, comme league la compte (`add()` dans le fuseau de PHP). */
    private function nowPlus(string $duration): int
    {
        return new DateTimeImmutable()->add(new DateInterval($duration))->getTimestamp();
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
            RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['http://localhost/callback', 'https://client.example.test/callback']),
        );
    }

    private function grantConnection(): McpConnection
    {
        $account = new CpgUser('jane', 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();
        $client = self::getContainer()->get(McpClientRepositoryInterface::class)->findOneByClientId(self::CLIENT_ID);
        self::assertInstanceOf(McpClient::class, $client);
        $connection = McpConnection::grant($account, $client, McpConnection::SCOPE_NOMINATIVE_READ, new DateTimeImmutable());
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
