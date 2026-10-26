<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Test d'intégration Doctrine (base `_test`) du modèle de la connexion MCP
 * (#647) : une connexion relie un compte, un client et un scope ; ses codes et
 * jetons n'y sont rattachés qu'en empreinte. Ce que la base doit garantir
 * d'elle-même est épinglé ici : l'unicité des empreintes, sous un nom de
 * contrainte qu'un appelant peut reconnaître, et la disparition de tout ce
 * qu'un compte a ouvert quand le compte disparaît.
 */
final class McpConnectionRepositoryTest extends KernelTestCase
{
    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    /** SQLSTATE de PostgreSQL pour une contrainte CHECK violée. */
    private const string CHECK_VIOLATION = '23514';

    private const string SCOPE = 'nominative:read';
    private const string AUDIENCE = 'https://example.test/api/mcp';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * Le rond-trip complet : chaque relation revient, et l'instant d'octroi
     * garde son décalage — choisi dans l'heure répétée du passage à l'heure
     * d'hiver, qu'une colonne sans fuseau relirait une heure plus tard (#434).
     */
    public function testAConnectionSurvivesARoundTrip(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $grantedAt = new DateTimeImmutable('2026-10-25T02:30:00+02:00');
        $connection = McpConnection::grant($account, $client, self::SCOPE, $grantedAt);
        $this->connections()->add($connection);
        $this->em->clear();

        $found = $this->connections()->findOneById($connection->getId());

        self::assertInstanceOf(McpConnection::class, $found);
        self::assertTrue($connection->getId()->equals($found->getId()));
        self::assertSame('jane', $found->account()->getUsername());
        self::assertSame('https://client.example.test/metadata.json', $found->client()->getClientId());
        self::assertSame('Client de test', $found->client()->getName());
        self::assertSame(['https://client.example.test/callback', 'http://127.0.0.1/callback'], $found->client()->redirectUris());
        self::assertSame(self::SCOPE, $found->scope());
        self::assertSame($grantedAt->getTimestamp(), $found->grantedAt()->getTimestamp());
        self::assertNull($found->lastUsedAt());
        self::assertFalse($found->isRevoked());
        self::assertNull($this->connections()->findOneById(Uuid::v7()));
    }

    /**
     * La connexion arrive avec son compte et son client, en une requête : le
     * futur authentificateur la relit à chaque appel (spec 0007).
     */
    public function testFindOneByIdLoadsTheAccountAndTheClientWithTheConnection(): void
    {
        $connection = $this->grantConnection();
        $this->em->clear();

        $found = $this->connections()->findOneById($connection->getId());

        self::assertInstanceOf(McpConnection::class, $found);
        self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($found->account()));
        self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($found->client()));
    }

    public function testAClientIsFoundByItsPublicIdentifier(): void
    {
        $this->registerClient('https://client.example.test/metadata.json');
        $this->em->clear();

        self::assertSame('Client de test', $this->clients()->findOneByClientId('https://client.example.test/metadata.json')?->getName());
        self::assertNull($this->clients()->findOneByClientId('https://other.example.test/metadata.json'));
    }

    /**
     * Deux clients ne partagent jamais un identifiant public : la base le
     * refuse sous un nom qu'un appelant peut reconnaître.
     */
    public function testAClientIdentifierIsUnique(): void
    {
        $this->registerClient('https://client.example.test/metadata.json');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_mcp_client_client_id');

        $this->registerClient('https://client.example.test/metadata.json');
    }

    /**
     * Une empreinte désigne un seul jeton : la recherche par empreinte ne peut
     * rendre que lui. La violation est nommée par sa contrainte, pas
     * seulement constatée.
     */
    public function testATokenHashIsUnique(): void
    {
        $connection = $this->grantConnection();
        $hash = hash('sha256', 'clear-access-token');
        $this->accessTokens()->add(new McpAccessToken($connection, $hash, self::AUDIENCE, new DateTimeImmutable('+1 hour')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_mcp_access_token_hash');

        $this->accessTokens()->add(new McpAccessToken($connection, $hash, self::AUDIENCE, new DateTimeImmutable('+1 hour')));
    }

    public function testAnAuthorizationCodeHashIsUnique(): void
    {
        $connection = $this->grantConnection();
        $hash = hash('sha256', 'clear-code');
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, $hash, new DateTimeImmutable('+10 minutes')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_mcp_authorization_code_hash');

        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, $hash, new DateTimeImmutable('+10 minutes')));
    }

    public function testARefreshTokenHashIsUnique(): void
    {
        $connection = $this->grantConnection();
        $hash = hash('sha256', 'clear-refresh-token');
        $this->refreshTokens()->add(new McpRefreshToken($connection, $hash, null, new DateTimeImmutable('+30 days')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_mcp_refresh_token_hash');

        $this->refreshTokens()->add(new McpRefreshToken($connection, $hash, null, new DateTimeImmutable('+30 days')));
    }

    /**
     * Chaque jeton se retrouve par son empreinte, avec sa connexion, et
     * l'empreinte d'un autre ne le rend pas.
     */
    public function testEachTokenIsFoundByItsHashWithItsConnection(): void
    {
        $connection = $this->grantConnection();
        $codeHash = hash('sha256', 'clear-code');
        $accessHash = hash('sha256', 'clear-access-token');
        $refreshHash = hash('sha256', 'clear-refresh-token');
        $accessToken = new McpAccessToken($connection, $accessHash, self::AUDIENCE, new DateTimeImmutable('+1 hour'));
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, $codeHash, new DateTimeImmutable('+10 minutes')));
        $this->accessTokens()->add($accessToken);
        $this->refreshTokens()->add(new McpRefreshToken($connection, $refreshHash, $accessToken, new DateTimeImmutable('+30 days')));
        $this->em->clear();

        $code = $this->authorizationCodes()->findOneByHash($codeHash);
        $access = $this->accessTokens()->findOneByHash($accessHash);
        $refresh = $this->refreshTokens()->findOneByHash($refreshHash);

        self::assertTrue($connection->getId()->equals($code?->connection()->getId()));
        self::assertSame($codeHash, $code?->codeHash());
        self::assertTrue($connection->getId()->equals($access?->connection()->getId()));
        self::assertSame(self::AUDIENCE, $access?->audience());
        self::assertTrue($connection->getId()->equals($refresh?->connection()->getId()));
        self::assertSame($accessHash, $refresh?->accessToken()?->tokenHash());
        self::assertNull($this->accessTokens()->findOneByHash($refreshHash));
        self::assertNull($this->refreshTokens()->findOneByHash($accessHash));
        self::assertNull($this->authorizationCodes()->findOneByHash($accessHash));
    }

    /**
     * La révocation par empreinte écrit l'instant une fois : une seconde
     * révocation ne déplace pas la première (le journal dira quand le jeton
     * est mort, pas quand on l'a constaté de nouveau).
     */
    public function testRevokeByHashRevokesOnceAndKeepsTheFirstInstant(): void
    {
        $connection = $this->grantConnection();
        $hash = hash('sha256', 'clear-access-token');
        $this->accessTokens()->add(new McpAccessToken($connection, $hash, self::AUDIENCE, new DateTimeImmutable('+1 hour')));
        $other = hash('sha256', 'other-access-token');
        $this->accessTokens()->add(new McpAccessToken($connection, $other, self::AUDIENCE, new DateTimeImmutable('+1 hour')));
        $first = new DateTimeImmutable('2026-10-23T10:00:00+02:00');

        $this->accessTokens()->revokeByHash($hash, $first);
        $this->accessTokens()->revokeByHash($hash, new DateTimeImmutable('2026-10-23T11:00:00+02:00'));
        $this->em->clear();

        $revoked = $this->accessTokens()->findOneByHash($hash);
        self::assertTrue($revoked?->isRevoked());
        self::assertSame($first->getTimestamp(), $revoked->revokedAt()?->getTimestamp());
        self::assertFalse($this->accessTokens()->findOneByHash($other)?->isRevoked(), "La révocation a touché le jeton d'une autre empreinte.");
    }

    public function testRevokeByHashRevokesCodesAndRefreshTokensToo(): void
    {
        $connection = $this->grantConnection();
        $codeHash = hash('sha256', 'clear-code');
        $refreshHash = hash('sha256', 'clear-refresh-token');
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, $codeHash, new DateTimeImmutable('+10 minutes')));
        $this->refreshTokens()->add(new McpRefreshToken($connection, $refreshHash, null, new DateTimeImmutable('+30 days')));

        $this->authorizationCodes()->revokeByHash($codeHash, new DateTimeImmutable());
        $this->refreshTokens()->revokeByHash($refreshHash, new DateTimeImmutable());
        $this->em->clear();

        self::assertTrue($this->authorizationCodes()->findOneByHash($codeHash)?->isRevoked());
        self::assertTrue($this->refreshTokens()->findOneByHash($refreshHash)?->isRevoked());
    }

    /**
     * La révocation d'une connexion (#649) : une instruction, qui rend de quoi
     * l'auditer — la connexion, le compte par son UUID, le client par son
     * identifiant public, la cause — et écrit l'instant et la cause en base.
     */
    public function testRevokeActiveRevokesOnceAndNamesTheAccountAndClient(): void
    {
        $account = $this->persistAccount('jane');
        $connection = McpConnection::grant($account, $this->registerClient('https://client.example.test/metadata.json'), self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($connection);
        $at = new DateTimeImmutable('2026-10-25T02:30:00+02:00');

        $revoked = $this->connections()->revokeActive($connection->getId(), RevocationCause::Client, $at);

        self::assertInstanceOf(RevokedConnection::class, $revoked);
        self::assertTrue($connection->getId()->equals($revoked->connectionId));
        self::assertSame($account->getId()->toRfc4122(), $revoked->accountId());
        self::assertSame('https://client.example.test/metadata.json', $revoked->clientId);
        self::assertSame(RevocationCause::Client, $revoked->cause);
        self::assertSame(
            ['revoked_at' => $at->getTimestamp(), 'revocation_cause' => 'client'],
            $this->revocationRow($connection),
        );
        $this->em->clear();
        $found = $this->connections()->findOneById($connection->getId());
        self::assertTrue($found?->isRevoked());
        self::assertSame(RevocationCause::Client, $found->revocationCause());
    }

    /**
     * La première révocation garde son instant et sa cause : une seconde,
     * quelle qu'en soit la cause, ne révoque rien et ne rend rien à auditer.
     */
    public function testASecondRevocationKeepsTheFirstInstantAndCause(): void
    {
        $connection = $this->grantConnection();
        $first = new DateTimeImmutable('2026-10-23T10:00:00+02:00');
        $this->connections()->revokeActive($connection->getId(), RevocationCause::RefreshTokenReuse, $first);

        $second = $this->connections()->revokeActive($connection->getId(), RevocationCause::Expired, new DateTimeImmutable('2026-10-23T11:00:00+02:00'));

        self::assertNull($second);
        self::assertSame(
            ['revoked_at' => $first->getTimestamp(), 'revocation_cause' => 'refresh-token-reuse'],
            $this->revocationRow($connection),
        );
    }

    public function testAnUnknownConnectionIsNotRevoked(): void
    {
        $connection = $this->grantConnection();

        self::assertNull($this->connections()->revokeActive(Uuid::v7(), RevocationCause::Client, new DateTimeImmutable()));
        self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($connection));
    }

    /**
     * Une autre connexion du même compte au même client reste active : la
     * révocation désigne une ligne par son identifiant, jamais un couple.
     */
    public function testRevokeActiveLeavesTheOtherConnectionsAlone(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $revoked = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        $other = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($revoked);
        $this->connections()->add($other);

        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Superseded, new DateTimeImmutable());

        self::assertSame('superseded', $this->revocationRow($revoked)['revocation_cause']);
        self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($other));
    }

    /**
     * La révocation est appelée après une transaction annulée (#649), dont
     * `wrapInTransaction()` a fermé l'EntityManager : elle passe par la
     * connexion DBAL, jamais par l'unité de travail, et tient donc encore.
     */
    public function testRevokeActiveWorksOnAClosedEntityManager(): void
    {
        $connection = $this->grantConnection();
        $this->em->close();

        $revoked = $this->connections()->revokeActive($connection->getId(), RevocationCause::CodeReplay, new DateTimeImmutable());

        self::assertFalse($this->em->isOpen());
        self::assertSame(RevocationCause::CodeReplay, $revoked?->cause);
        self::assertSame('code-replay', $this->revocationRow($connection)['revocation_cause']);
    }

    /**
     * Une révocation sans cause, ou une cause sans révocation, est refusée
     * par la base elle-même : une écriture SQL qui contournerait le dépôt ne
     * peut laisser ni l'une ni l'autre.
     *
     * @return iterable<string, array{string}>
     */
    public static function halfRevocations(): iterable
    {
        yield 'un instant sans cause' => ['UPDATE mcp_connection SET revoked_at = now()'];
        yield 'une cause sans instant' => ["UPDATE mcp_connection SET revocation_cause = 'client'"];
    }

    #[DataProvider('halfRevocations')]
    public function testTheDatabaseRefusesARevocationWithoutCause(string $update): void
    {
        $this->grantConnection();

        try {
            $this->em->getConnection()->executeStatement($update);
            self::fail('La base aurait dû refuser une révocation à moitié écrite.');
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString('chk_mcp_connection_revocation_cause', $exception->getMessage());
        }
    }

    /**
     * Le rafraîchissement survit au jeton d'accès qu'il a accompagné : la
     * purge d'un jeton d'accès expiré ne doit pas emporter la possibilité de
     * rafraîchir (`ON DELETE SET NULL`).
     */
    public function testDeletingAnAccessTokenKeepsItsRefreshToken(): void
    {
        $connection = $this->grantConnection();
        $accessToken = new McpAccessToken($connection, hash('sha256', 'clear-access-token'), self::AUDIENCE, new DateTimeImmutable('+1 hour'));
        $refreshHash = hash('sha256', 'clear-refresh-token');
        $this->accessTokens()->add($accessToken);
        $this->refreshTokens()->add(new McpRefreshToken($connection, $refreshHash, $accessToken, new DateTimeImmutable('+30 days')));

        $this->em->getConnection()->executeStatement('DELETE FROM mcp_access_token WHERE id = :id', ['id' => $accessToken->getId()], ['id' => UuidType::NAME]);
        $this->em->clear();

        $refresh = $this->refreshTokens()->findOneByHash($refreshHash);
        self::assertInstanceOf(McpRefreshToken::class, $refresh);
        self::assertNull($refresh->accessToken());
    }

    /**
     * Un compte supprimé n'a plus aucune connexion MCP, ni code ni jeton : la
     * cascade est celle de la base, elle tient pour une suppression en SQL
     * comme pour la purge des invitations. Le client, lui, reste : il n'est
     * à personne.
     */
    public function testDeletingTheAccountDeletesItsConnectionsAndTokens(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $connection = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($connection);
        $accessToken = new McpAccessToken($connection, hash('sha256', 'clear-access-token'), self::AUDIENCE, new DateTimeImmutable('+1 hour'));
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, hash('sha256', 'clear-code'), new DateTimeImmutable('+10 minutes')));
        $this->accessTokens()->add($accessToken);
        $this->refreshTokens()->add(new McpRefreshToken($connection, hash('sha256', 'clear-refresh-token'), $accessToken, new DateTimeImmutable('+30 days')));
        // Une connexion d'un autre compte au même client, qui doit survivre.
        $survivor = McpConnection::grant($this->persistAccount('john'), $client, self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($survivor);

        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $account->getId()], ['id' => UuidType::NAME]);
        $this->em->clear();

        self::assertSame(1, $this->rowCount('mcp_connection'));
        self::assertNotNull($this->connections()->findOneById($survivor->getId()));
        self::assertSame(0, $this->rowCount('mcp_authorization_code'));
        self::assertSame(0, $this->rowCount('mcp_access_token'));
        self::assertSame(0, $this->rowCount('mcp_refresh_token'));
        self::assertSame(1, $this->rowCount('mcp_client'));
    }

    /**
     * Un client retiré n'a plus de connexion, ni de code ni de jeton : la
     * cascade passe par `mcp_connection.mcp_client_id`. La connexion d'un autre
     * client reste, et les comptes aussi.
     */
    public function testDeletingAClientDeletesItsConnectionsAndTokens(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $connection = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($connection);
        $accessToken = new McpAccessToken($connection, hash('sha256', 'clear-access-token'), self::AUDIENCE, new DateTimeImmutable('+1 hour'));
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, hash('sha256', 'clear-code'), new DateTimeImmutable('+10 minutes')));
        $this->accessTokens()->add($accessToken);
        $this->refreshTokens()->add(new McpRefreshToken($connection, hash('sha256', 'clear-refresh-token'), $accessToken, new DateTimeImmutable('+30 days')));
        // Une connexion du même compte à un autre client, qui doit survivre.
        $survivor = McpConnection::grant($account, $this->registerClient('https://other.example.test/metadata.json'), self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($survivor);
        $this->accessTokens()->add(new McpAccessToken($survivor, hash('sha256', 'survivor-access-token'), self::AUDIENCE, new DateTimeImmutable('+1 hour')));

        $this->em->getConnection()->executeStatement('DELETE FROM mcp_client WHERE id = :id', ['id' => $client->getId()], ['id' => UuidType::NAME]);
        $this->em->clear();

        self::assertSame(1, $this->rowCount('mcp_connection'));
        self::assertNotNull($this->connections()->findOneById($survivor->getId()));
        self::assertNull($this->connections()->findOneById($connection->getId()));
        self::assertSame(0, $this->rowCount('mcp_authorization_code'));
        self::assertSame(1, $this->rowCount('mcp_access_token'));
        self::assertNotNull($this->accessTokens()->findOneByHash(hash('sha256', 'survivor-access-token')));
        self::assertSame(0, $this->rowCount('mcp_refresh_token'));
        self::assertSame(1, $this->rowCount('cpg_user'));
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

    private function rowCount(string $table): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * L'instant (en secondes) et la cause de révocation de la connexion, lus
     * en base, hors de l'unité de travail.
     *
     * @return array{revoked_at: int|null, revocation_cause: string|null}
     */
    private function revocationRow(McpConnection $connection): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT EXTRACT(EPOCH FROM revoked_at)::bigint AS revoked_at, revocation_cause FROM mcp_connection WHERE id = :id',
            ['id' => $connection->getId()],
            ['id' => UuidType::NAME],
        );
        self::assertIsArray($row);
        self::assertTrue(null === $row['revoked_at'] || \is_int($row['revoked_at']));
        self::assertTrue(null === $row['revocation_cause'] || \is_string($row['revocation_cause']));

        return ['revoked_at' => $row['revoked_at'], 'revocation_cause' => $row['revocation_cause']];
    }

    private function persistAccount(string $username): CpgUser
    {
        $account = new CpgUser($username, 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function registerClient(string $clientId): McpClient
    {
        $client = RegisteredMcpClient::of($clientId, 'Client de test', ['https://client.example.test/callback', 'http://127.0.0.1/callback']);
        $this->clients()->add($client);

        return $client;
    }

    private function grantConnection(): McpConnection
    {
        $connection = McpConnection::grant($this->persistAccount('jane'), $this->registerClient('https://client.example.test/metadata.json'), self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($connection);

        return $connection;
    }

    private function clients(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function authorizationCodes(): McpAuthorizationCodeRepositoryInterface
    {
        return self::getContainer()->get(McpAuthorizationCodeRepositoryInterface::class);
    }

    private function accessTokens(): McpAccessTokenRepositoryInterface
    {
        return self::getContainer()->get(McpAccessTokenRepositoryInterface::class);
    }

    private function refreshTokens(): McpRefreshTokenRepositoryInterface
    {
        return self::getContainer()->get(McpRefreshTokenRepositoryInterface::class);
    }
}
