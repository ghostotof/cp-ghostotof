<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\AuthorizationCodeReplayedException;
use App\Mcp\Connection\Domain\Exception\RotatedRefreshTokenReusedException;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Infrastructure\League\AccessTokenOfRefreshTokenNotFoundException;
use App\Mcp\Connection\Infrastructure\League\AccessTokenRepository;
use App\Mcp\Connection\Infrastructure\League\AuthCodeRepository;
use App\Mcp\Connection\Infrastructure\League\ClientRepository;
use App\Mcp\Connection\Infrastructure\League\ConnectionOfToken;
use App\Mcp\Connection\Infrastructure\League\McpConnectionOfAnotherClientException;
use App\Mcp\Connection\Infrastructure\League\NominativeReadScope;
use App\Mcp\Connection\Infrastructure\League\OAuthClient;
use App\Mcp\Connection\Infrastructure\League\RefreshTokenRepository;
use App\Mcp\Connection\Infrastructure\League\RevokedMcpConnectionException;
use App\Mcp\Connection\Infrastructure\League\ScopeRepository;
use App\Mcp\Connection\Infrastructure\League\UnknownMcpConnectionException;
use App\Mcp\Connection\Infrastructure\Security\TrustedTier;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
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

        self::assertFalse($this->authCodeIsRevoked($codeIdentifier));
        self::assertFalse($this->accessTokens()->isAccessTokenRevoked($accessIdentifier));
        self::assertFalse($this->refreshTokenIsRevoked($refreshIdentifier));

        $this->em->getConnection()->executeStatement(
            "UPDATE mcp_connection SET revoked_at = NOW(), revocation_cause = 'client' WHERE id = :id",
            ['id' => $connection->getId()],
            ['id' => UuidType::NAME],
        );
        $this->em->clear();

        self::assertTrue($this->authCodeIsRevoked($codeIdentifier));
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($accessIdentifier));
        self::assertTrue($this->refreshTokenIsRevoked($refreshIdentifier));
    }

    /**
     * Un identifiant que la base ne connaît pas est révoqué : une ligne
     * purgée, ou un identifiant qui n'a jamais été émis ici, ne vaut rien.
     */
    public function testAnUnknownTokenIsRevoked(): void
    {
        self::assertTrue($this->authCodeIsRevoked($this->identifier()));
        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($this->identifier()));
        self::assertTrue($this->refreshTokenIsRevoked($this->identifier()));
    }

    /**
     * L'échéance d'un rafraîchissement se juge sur l'horloge de l'application
     * (ClockInterface), jamais sur `time()` (#649, le report « deux
     * horloges ») : un jeton dont l'échéance, réelle, est encore à venir pour
     * `time()` — le contrôle de league, qui précède ce dépôt et reste un
     * second filet, le dirait valide — est mort dès que l'horloge de
     * l'application l'atteint, à la seconde près, et vivant la seconde d'avant.
     */
    public function testARefreshTokenExpiryIsJudgedOnTheApplicationClock(): void
    {
        $connection = $this->grantConnection();
        $refreshIdentifier = $this->identifier();
        $expiresAt = new DateTimeImmutable('+1 day');
        $this->persistRefreshToken($this->persistAccessToken($connection, $this->identifier()), $refreshIdentifier, $expiresAt);
        self::assertLessThan($expiresAt->getTimestamp(), time(), 'Pour `time()`, le jeton vaut encore.');

        $this->clock = new MockClock($expiresAt->sub(new DateInterval('PT1S')));
        self::assertFalse($this->refreshTokenIsRevoked($refreshIdentifier));

        $this->clock = new MockClock($expiresAt);
        self::assertTrue($this->refreshTokenIsRevoked($refreshIdentifier));
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

        self::assertTrue($this->accessTokens()->isAccessTokenRevoked($revoked));
        self::assertFalse($this->accessTokens()->isAccessTokenRevoked($kept));
        // Un code n'est révoqué que par son échange : revenu sur une
        // connexion active, il est rejoué (#649), et le dépôt le dit par
        // l'exception que le point de jeton rattrape pour révoquer la
        // connexion — jamais par un simple « révoqué ».
        try {
            $this->authCodeIsRevoked($codeIdentifier);
            self::fail('Un code échangé qui revient est un rejeu.');
        } catch (AuthorizationCodeReplayedException $replay) {
            self::assertTrue($replay->connectionId()->equals($connection->getId()));
            self::assertSame(RevocationCause::CodeReplay, $replay->revocationCause());
        }
        // Un rafraîchissement n'est révoqué que par sa rotation : revenu sur
        // une connexion active, il est réutilisé (#649), même exception, même
        // rattrapage.
        try {
            $this->refreshTokenIsRevoked($refreshIdentifier);
            self::fail('Un rafraîchissement tourné qui revient est une réutilisation.');
        } catch (RotatedRefreshTokenReusedException $reuse) {
            self::assertTrue($reuse->connectionId()->equals($connection->getId()));
            self::assertSame(RevocationCause::RefreshTokenReuse, $reuse->revocationCause());
        }
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
     * Rien ne naît d'une connexion révoquée : ni code, ni jeton d'accès, ni
     * rafraîchissement, même si league ne l'a pas vue révoquée un instant
     * plus tôt (une course).
     */
    public function testNothingIsIssuedForARevokedConnection(): void
    {
        $connection = $this->grantConnection();
        $accessToken = $this->persistAccessToken($connection, $this->identifier());
        $this->em->getConnection()->executeStatement(
            "UPDATE mcp_connection SET revoked_at = NOW(), revocation_cause = 'client' WHERE id = :id",
            ['id' => $connection->getId()],
            ['id' => UuidType::NAME],
        );
        $this->em->clear();

        $issuances = [
            'code' => fn () => $this->persistAuthCode($connection, $this->identifier()),
            'jeton d\'accès' => fn (): AccessTokenEntityInterface => $this->persistAccessToken($connection, $this->identifier()),
            'rafraîchissement' => fn () => $this->persistRefreshToken($accessToken, $this->identifier()),
        ];

        foreach ($issuances as $kind => $issue) {
            try {
                $issue();
                self::fail(\sprintf('Le %s aurait dû être refusé.', $kind));
            } catch (RevokedMcpConnectionException) {
                // Attendu.
            }
        }

        self::assertSame(0, $this->rowCount('mcp_authorization_code'));
        self::assertSame(1, $this->rowCount('mcp_access_token'), 'Seul le jeton émis avant la révocation reste.');
        self::assertSame(0, $this->rowCount('mcp_refresh_token'));
    }

    /**
     * Un rafraîchissement dont le jeton d'accès n'est pas en base est un
     * défaut du serveur : jamais enregistré sans son lien, que la rotation
     * (#649) lit.
     */
    public function testARefreshTokenWhoseAccessTokenIsNotPersistedIsRefused(): void
    {
        $connection = $this->grantConnection();
        $accessToken = $this->accessTokens()->getNewToken(new OAuthClient($connection->client()), [new NominativeReadScope()], $connection->getId()->toRfc4122());
        $accessToken->setExpiryDateTime($this->clock->now()->add(new DateInterval('PT1H')));
        $accessToken->setIdentifier($this->identifier());

        try {
            $this->persistRefreshToken($accessToken, $this->identifier());
            self::fail('Le rafraîchissement aurait dû être refusé.');
        } catch (AccessTokenOfRefreshTokenNotFoundException) {
            // Attendu.
        }

        self::assertSame(0, $this->rowCount('mcp_refresh_token'));
    }

    /**
     * Une empreinte déjà prise est rendue à league sous l'exception qu'il
     * nomme pour ce cas, jamais la violation de Doctrine (dont le DETAIL cite
     * l'empreinte). Ce n'est **pas** un nouvel essai qui aboutirait : league
     * en tenterait un sous un autre identifiant, mais le flush refusé a fermé
     * l'EntityManager, et cet essai échoue à son tour — la requête finit en
     * erreur. Accepté : une collision de 40 octets aléatoires n'arrive pas ;
     * ce test épingle seulement le nom de la cause.
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
     * Un identifiant hors de la forme du domaine (McpClientId) n'est pas cherché :
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
     * aucun ne l'est ici, et la révocation ne l'appelle pas non plus. Ce test
     * épingle la méthode seule, pour qui l'appellerait un jour : un client
     * connu, sans secret, et l'un des deux grants servis. Ce qu'un secret
     * présenté devient vraiment au point de jeton (ignoré, league ne lisant
     * pas ce dépôt) est épinglé par
     * `TokenControllerTest::testASuperfluousClientSecretIsIgnored`.
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
    private function persistRefreshToken(AccessTokenEntityInterface $accessToken, string $identifier, ?DateTimeImmutable $expiresAt = null): void
    {
        $refresh = $this->refreshTokens()->getNewRefreshToken();
        $refresh->setAccessToken($accessToken);
        $refresh->setExpiryDateTime($expiresAt ?? $this->clock->now()->add(new DateInterval('P30D')));
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

    private function rowCount(string $table): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
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

    private function registerClient(string $clientId): McpClient
    {
        $client = RegisteredMcpClient::of($clientId, 'Client de test', ['https://client.example.test/callback', 'http://localhost/callback']);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($client);

        return $client;
    }

    private function grantConnection(): McpConnection
    {
        // Le palier de confiance, que le rafraîchissement exige du compte (#649).
        $account = new CpgUser('jane', 'hashed-password');
        $account->setRoles([CpgUser::ROLE_TRUSTED]);
        $this->em->persist($account);
        $this->em->flush();
        $connection = McpConnection::grant($account, $this->registerClient(self::CLIENT_ID), McpConnection::SCOPE_NOMINATIVE_READ, new DateTimeImmutable());
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
            $this->connections(),
            new ConnectionOfToken($this->connections()),
            $this->clock,
        );
    }

    /**
     * Le jugement d'un code se fait sous le verrou de sa connexion (#649),
     * qui exige une transaction : celle du point de jeton en production, une
     * transaction annulée ici.
     */
    private function authCodeIsRevoked(string $identifier): bool
    {
        $this->em->beginTransaction();

        try {
            return $this->authCodes()->isAuthCodeRevoked($identifier);
        } finally {
            $this->em->rollback();
            $this->em->clear();
        }
    }

    private function refreshTokens(): RefreshTokenRepository
    {
        return new RefreshTokenRepository(
            self::getContainer()->get(McpRefreshTokenRepositoryInterface::class),
            self::getContainer()->get(McpAccessTokenRepositoryInterface::class),
            $this->connections(),
            new ConnectionOfToken($this->connections()),
            self::getContainer()->get(ConnectionLifetimes::class),
            self::getContainer()->get(TrustedTier::class),
            $this->clock,
        );
    }

    /**
     * Le jugement d'un rafraîchissement se fait sous le verrou de sa
     * connexion (#649), qui exige une transaction : celle du point de jeton
     * en production, une transaction annulée ici — ce que le jugement écrit
     * (le dernier usage) ne survit pas au test.
     */
    private function refreshTokenIsRevoked(string $identifier): bool
    {
        $this->em->beginTransaction();

        try {
            return $this->refreshTokens()->isRefreshTokenRevoked($identifier);
        } finally {
            $this->em->rollback();
            $this->em->clear();
        }
    }
}
