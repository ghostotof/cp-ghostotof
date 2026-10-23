<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Infrastructure\League\AccessTokenRepository;
use App\Mcp\Connection\Infrastructure\League\AuthCodeRepository;
use App\Mcp\Connection\Infrastructure\League\ClientRepository;
use App\Mcp\Connection\Infrastructure\League\ConnectionOfToken;
use App\Mcp\Connection\Infrastructure\League\McpConnectionOfAnotherClientException;
use App\Mcp\Connection\Infrastructure\League\NominativeReadScope;
use App\Mcp\Connection\Infrastructure\League\OAuthClient;
use App\Mcp\Connection\Infrastructure\League\RefreshTokenRepository;
use App\Mcp\Connection\Infrastructure\League\ScopeRepository;
use App\Mcp\Connection\Infrastructure\League\UnknownMcpConnectionException;
use App\Security\User\Domain\Entity\CpgUser;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Les dépôts que league lit et écrit (#647), sur la base `_test` : ce que le
 * serveur d'autorisation garde d'un jeton, et ce qui le tient pour mort.
 *
 * L'« utilisateur » de league est la connexion MCP, par son UUID : chaque
 * jeton se rattache à elle, et meurt avec elle.
 */
final class LeagueRepositoriesTest extends KernelTestCase
{
    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    private EntityManagerInterface $em;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-23T10:00:00+02:00'));

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * Ni l'identifiant (qui **est** le jeton présenté en `Bearer`) ni rien qui
     * le contienne n'atteint la table : son empreinte seule, et l'audience
     * de la ressource MCP.
     */
    public function testAPersistedAccessTokenIsStoredAsItsHashOnly(): void
    {
        $connection = $this->grantConnection();
        $identifier = $this->identifier();

        $this->persistAccessToken($connection, $identifier);

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM mcp_access_token');
        self::assertCount(1, $rows);
        $this->assertNoColumnHolds($identifier, $rows[0]);
        self::assertSame(hash('sha256', $identifier), $rows[0]['token_hash']);
        self::assertSame($this->endpoints()->resource(), $rows[0]['audience']);
    }

    public function testAPersistedAuthorizationCodeAndRefreshTokenAreStoredAsTheirHashOnly(): void
    {
        $connection = $this->grantConnection();
        $codeIdentifier = $this->identifier();
        $accessIdentifier = $this->identifier();
        $refreshIdentifier = $this->identifier();

        $this->persistAuthCode($connection, $codeIdentifier);
        $accessToken = $this->persistAccessToken($connection, $accessIdentifier);
        $this->persistRefreshToken($accessToken, $refreshIdentifier);

        $code = $this->em->getConnection()->fetchAssociative('SELECT * FROM mcp_authorization_code');
        self::assertIsArray($code);
        $this->assertNoColumnHolds($codeIdentifier, $code);
        self::assertSame(hash('sha256', $codeIdentifier), $code['code_hash']);

        $refresh = $this->em->getConnection()->fetchAssociative('SELECT * FROM mcp_refresh_token');
        self::assertIsArray($refresh);
        $this->assertNoColumnHolds($refreshIdentifier, $refresh);
        $this->assertNoColumnHolds($accessIdentifier, $refresh);
        self::assertSame(hash('sha256', $refreshIdentifier), $refresh['token_hash']);

        // Le rafraîchissement garde le jeton d'accès émis avec lui, que la
        // rotation (#649) révoquera.
        $accessRowId = $this->em->getConnection()->fetchOne('SELECT id FROM mcp_access_token');
        self::assertSame($accessRowId, $refresh['access_token_id']);
    }

    /**
     * Révoquer la connexion révoque tout ce qui en dépend, sans toucher aux
     * lignes des jetons : league demande « révoqué ? » et la connexion répond.
     */
    public function testATokenOfARevokedConnectionIsRevoked(): void
    {
        $connection = $this->grantConnection();
        $codeIdentifier = $this->identifier();
        $accessIdentifier = $this->identifier();
        $refreshIdentifier = $this->identifier();
        $this->persistAuthCode($connection, $codeIdentifier);
        $this->persistRefreshToken($this->persistAccessToken($connection, $accessIdentifier), $refreshIdentifier);

        self::assertFalse($this->authCodes()->isAuthCodeRevoked($codeIdentifier));
        self::assertFalse($this->accessTokens()->isAccessTokenRevoked($accessIdentifier));
        self::assertFalse($this->refreshTokens()->isRefreshTokenRevoked($refreshIdentifier));

        $this->em->getConnection()->executeStatement(
            'UPDATE mcp_connection SET revoked_at = NOW() WHERE id = :id',
            ['id' => $connection->getId()],
            ['id' => UuidType::NAME],
        );
        $this->em->clear();

        self::assertTrue($this->authCodes()->isAuthCodeRevoked($codeIdentifier));
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($accessIdentifier));
        self::assertTrue($this->refreshTokens()->isRefreshTokenRevoked($refreshIdentifier));
    }

    /**
     * Un identifiant que la base ne connaît pas est révoqué : une ligne
     * purgée, ou un identifiant qui n'a jamais été émis ici, ne vaut rien.
     */
    public function testAnUnknownTokenIsRevoked(): void
    {
        self::assertTrue($this->authCodes()->isAuthCodeRevoked($this->identifier()));
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($this->identifier()));
        self::assertTrue($this->refreshTokens()->isRefreshTokenRevoked($this->identifier()));
    }

    /**
     * La révocation par league vise un seul jeton, par l'empreinte de son
     * identifiant, à l'instant de l'horloge.
     */
    public function testRevokingATokenRevokesItAloneAtTheClockInstant(): void
    {
        $connection = $this->grantConnection();
        $codeIdentifier = $this->identifier();
        $revoked = $this->identifier();
        $kept = $this->identifier();
        $refreshIdentifier = $this->identifier();
        $this->persistAuthCode($connection, $codeIdentifier);
        $this->persistRefreshToken($this->persistAccessToken($connection, $revoked), $refreshIdentifier);
        $this->persistAccessToken($connection, $kept);

        $this->authCodes()->revokeAuthCode($codeIdentifier);
        $this->accessTokens()->revokeAccessToken($revoked);
        $this->refreshTokens()->revokeRefreshToken($refreshIdentifier);
        $this->em->clear();

        self::assertTrue($this->authCodes()->isAuthCodeRevoked($codeIdentifier));
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($revoked));
        self::assertFalse($this->accessTokens()->isAccessTokenRevoked($kept));
        self::assertTrue($this->refreshTokens()->isRefreshTokenRevoked($refreshIdentifier));
        $revokedAt = $this->em->getConnection()->fetchOne(
            'SELECT EXTRACT(EPOCH FROM revoked_at)::bigint FROM mcp_access_token WHERE token_hash = :hash',
            ['hash' => hash('sha256', $revoked)],
        );
        self::assertSame($this->clock->now()->getTimestamp(), $revokedAt);
    }

    /**
     * L'« utilisateur » d'un jeton est l'UUID d'une connexion existante : un
     * autre identifiant est un défaut de câblage du serveur, jamais une
     * erreur OAuth à renvoyer au client.
     */
    #[DataProvider('unusableOwners')]
    public function testATokenForAnUnknownConnectionIsAServerFault(?string $owner): void
    {
        $client = $this->registerClient(self::CLIENT_ID);
        $token = $this->accessTokens()->getNewToken(new OAuthClient($client), [new NominativeReadScope()], $owner);
        $token->setExpiryDateTime($this->clock->now()->add(new DateInterval('PT1H')));
        $token->setIdentifier($this->identifier());

        $this->expectException(UnknownMcpConnectionException::class);

        $this->accessTokens()->persistNewAccessToken($token);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableOwners(): iterable
    {
        yield 'aucun' => [null];
        yield 'pas un UUID' => ['jane'];
        yield 'UUID inconnu' => [Uuid::v7()->toRfc4122()];
    }

    public function testAnAuthorizationCodeForAnUnknownConnectionIsAServerFault(): void
    {
        $code = $this->authCodes()->getNewAuthCode();
        $code->setClient(new OAuthClient($this->registerClient(self::CLIENT_ID)));
        $code->setUserIdentifier(Uuid::v7()->toRfc4122());
        $code->setExpiryDateTime($this->clock->now()->add(new DateInterval('PT10M')));
        $code->setIdentifier($this->identifier());

        $this->expectException(UnknownMcpConnectionException::class);

        $this->authCodes()->persistNewAuthCode($code);
    }

    /**
     * Un jeton émis pour un client sur la connexion d'un autre client mêlerait
     * deux consentements : refusé, comme un défaut du serveur.
     */
    public function testATokenOnTheConnectionOfAnotherClientIsAServerFault(): void
    {
        $connection = $this->grantConnection();
        $other = $this->registerClient('https://other.example.test/metadata.json');
        $token = $this->accessTokens()->getNewToken(new OAuthClient($other), [new NominativeReadScope()], $connection->getId()->toRfc4122());
        $token->setExpiryDateTime($this->clock->now()->add(new DateInterval('PT1H')));
        $token->setIdentifier($this->identifier());

        $this->expectException(McpConnectionOfAnotherClientException::class);

        $this->accessTokens()->persistNewAccessToken($token);
    }

    /**
     * Une empreinte déjà prise est rendue à league sous l'exception qu'il
     * attend pour tirer un autre identifiant.
     */
    public function testADuplicateIdentifierIsReportedToLeague(): void
    {
        $connection = $this->grantConnection();
        $identifier = $this->identifier();
        $this->persistAccessToken($connection, $identifier);

        $this->expectException(UniqueTokenIdentifierConstraintViolationException::class);

        $this->persistAccessToken($connection, $identifier);
    }

    public function testAKnownClientIsAPublicClientWithItsRegisteredRedirects(): void
    {
        $this->registerClient(self::CLIENT_ID);
        $this->em->clear();

        $client = $this->clients()->getClientEntity(self::CLIENT_ID);

        self::assertInstanceOf(OAuthClient::class, $client);
        self::assertSame(self::CLIENT_ID, $client->getIdentifier());
        self::assertSame('Client de test', $client->getName());
        self::assertSame(['https://client.example.test/callback', 'http://localhost/callback'], $client->getRedirectUri());
        self::assertFalse($client->isConfidential());
        self::assertSame(self::CLIENT_ID, $client->mcpClient()->getClientId());
        self::assertNull($this->clients()->getClientEntity('https://unknown.example.test/metadata.json'));
    }

    /**
     * Un identifiant que la colonne ne peut pas contenir n'est pas cherché :
     * PostgreSQL lit un paramètre texte comme une chaîne C (`…\0x` trouverait
     * le client `…`) et refuse un octet hors UTF-8 par une erreur — un 500 à
     * la main de quiconque.
     */
    #[DataProvider('unreadableClientIds')]
    public function testAnUnreadableClientIdIsUnknownWithoutAQuery(string $clientId): void
    {
        $this->registerClient(self::CLIENT_ID);

        self::assertNull($this->clients()->getClientEntity($clientId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableClientIds(): iterable
    {
        yield 'octet nul' => [self::CLIENT_ID."\0x"];
        yield 'caractère de contrôle' => [self::CLIENT_ID."\n"];
        yield 'hors UTF-8' => ["https://client.example.test/\xff"];
        yield 'trop long' => ['https://client.example.test/'.str_repeat('a', McpClient::CLIENT_ID_MAX_LENGTH)];
    }

    /**
     * league n'appelle `validateClient()` que pour un client confidentiel ;
     * aucun ne l'est ici. Écrit quand même pour qui l'appellerait (la
     * révocation, plus tard) : un client connu, sans secret, et l'un des deux
     * grants servis.
     */
    #[DataProvider('clientValidations')]
    public function testValidateClientAcceptsOnlyAKnownPublicClientOnTheServedGrants(string $clientId, ?string $presentedSecret, ?string $grantType, bool $expected): void
    {
        $this->registerClient(self::CLIENT_ID);

        self::assertSame($expected, $this->clients()->validateClient($clientId, $presentedSecret, $grantType));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, bool}>
     */
    public static function clientValidations(): iterable
    {
        yield 'code d\'autorisation' => [self::CLIENT_ID, null, 'authorization_code', true];
        yield 'rafraîchissement' => [self::CLIENT_ID, null, 'refresh_token', true];
        yield 'secret vide (league l\'écrit ainsi)' => [self::CLIENT_ID, '', 'refresh_token', true];
        yield 'un secret présenté' => [self::CLIENT_ID, 'whatever', 'authorization_code', false];
        yield 'grant non servi' => [self::CLIENT_ID, null, 'client_credentials', false];
        yield 'aucun grant' => [self::CLIENT_ID, null, null, false];
        yield 'client inconnu' => ['https://unknown.example.test/metadata.json', null, 'authorization_code', false];
    }

    public function testAClientSupportsOnlyTheServedGrants(): void
    {
        $client = new OAuthClient($this->registerClient(self::CLIENT_ID));

        self::assertTrue($client->supportsGrantType('authorization_code'));
        self::assertTrue($client->supportsGrantType('refresh_token'));
        self::assertFalse($client->supportsGrantType('client_credentials'));
        self::assertFalse($client->supportsGrantType('password'));
        self::assertFalse($client->supportsGrantType('implicit'));
    }

    public function testTheOnlyScopeIsNominativeRead(): void
    {
        $scopes = new ScopeRepository();
        $client = new OAuthClient($this->registerClient(self::CLIENT_ID));

        self::assertSame('nominative:read', $scopes->getScopeEntityByIdentifier('nominative:read')?->getIdentifier());
        self::assertNull($scopes->getScopeEntityByIdentifier('openid'));
        self::assertNull($scopes->getScopeEntityByIdentifier('Nominative:read'));

        foreach ([[], [new NominativeReadScope(), new NominativeReadScope()]] as $requested) {
            $finalized = $scopes->finalizeScopes($requested, 'authorization_code', $client, Uuid::v7()->toRfc4122());

            self::assertSame(['nominative:read'], array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $finalized));
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertNoColumnHolds(string $identifier, array $row): void
    {
        foreach ($row as $column => $value) {
            self::assertStringNotContainsString($identifier, (string) (\is_scalar($value) ? $value : ''), \sprintf('La colonne %s contient l\'identifiant en clair.', $column));
        }
    }

    /**
     * @param non-empty-string $identifier
     */
    private function persistAccessToken(McpConnection $connection, string $identifier): AccessTokenEntityInterface
    {
        $token = $this->accessTokens()->getNewToken(new OAuthClient($connection->client()), [new NominativeReadScope()], $connection->getId()->toRfc4122());
        $token->setExpiryDateTime($this->clock->now()->add(new DateInterval('PT1H')));
        $token->setIdentifier($identifier);
        $this->accessTokens()->persistNewAccessToken($token);

        return $token;
    }

    /**
     * @param non-empty-string $identifier
     */
    private function persistAuthCode(McpConnection $connection, string $identifier): void
    {
        $code = $this->authCodes()->getNewAuthCode();
        $code->setClient(new OAuthClient($connection->client()));
        $code->setUserIdentifier($connection->getId()->toRfc4122());
        $code->setRedirectUri('https://client.example.test/callback');
        $code->addScope(new NominativeReadScope());
        $code->setExpiryDateTime($this->clock->now()->add(new DateInterval('PT10M')));
        $code->setIdentifier($identifier);
        $this->authCodes()->persistNewAuthCode($code);
    }

    /**
     * @param non-empty-string $identifier
     */
    private function persistRefreshToken(AccessTokenEntityInterface $accessToken, string $identifier): void
    {
        $refresh = $this->refreshTokens()->getNewRefreshToken();
        $refresh->setAccessToken($accessToken);
        $refresh->setExpiryDateTime($this->clock->now()->add(new DateInterval('P30D')));
        $refresh->setIdentifier($identifier);
        $this->refreshTokens()->persistNewRefreshToken($refresh);
    }

    /**
     * Ce que league tire : 40 octets aléatoires en hexadécimal.
     *
     * @return non-empty-string
     */
    private function identifier(): string
    {
        /** @var non-empty-string $identifier 80 caractères */
        $identifier = bin2hex(random_bytes(40));

        return $identifier;
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

    private function registerClient(string $clientId): McpClient
    {
        $client = McpClient::register($clientId, 'Client de test', ['https://client.example.test/callback', 'http://localhost/callback'], new DateTimeImmutable());
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($client);

        return $client;
    }

    private function grantConnection(): McpConnection
    {
        $account = new CpgUser('jane', 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();
        $connection = McpConnection::grant($account, $this->registerClient(self::CLIENT_ID), NominativeReadScope::IDENTIFIER, new DateTimeImmutable());
        $this->connections()->add($connection);

        return $connection;
    }

    private function endpoints(): OAuthEndpoints
    {
        return new OAuthEndpoints('https://site.example', 'https://site.example');
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function clients(): ClientRepository
    {
        return new ClientRepository(self::getContainer()->get(McpClientRepositoryInterface::class));
    }

    private function accessTokens(): AccessTokenRepository
    {
        return new AccessTokenRepository(
            self::getContainer()->get(McpAccessTokenRepositoryInterface::class),
            new ConnectionOfToken($this->connections()),
            $this->endpoints(),
            $this->clock,
        );
    }

    private function authCodes(): AuthCodeRepository
    {
        return new AuthCodeRepository(
            self::getContainer()->get(McpAuthorizationCodeRepositoryInterface::class),
            new ConnectionOfToken($this->connections()),
            $this->clock,
        );
    }

    private function refreshTokens(): RefreshTokenRepository
    {
        return new RefreshTokenRepository(
            self::getContainer()->get(McpRefreshTokenRepositoryInterface::class),
            self::getContainer()->get(McpAccessTokenRepositoryInterface::class),
            new ConnectionOfToken($this->connections()),
            $this->clock,
        );
    }
}
