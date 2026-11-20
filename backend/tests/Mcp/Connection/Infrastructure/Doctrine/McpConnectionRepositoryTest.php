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
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\ExchangedExpiredCursor;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Mcp\Connection\Infrastructure\Doctrine\RevocationOutsideTransactionException;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\TestDatabase;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
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
     * Le premier échange d'un code est noté sur sa connexion, une fois (#650, R4) :
     * une seconde note ne déplace pas la première, la connexion d'un autre code
     * n'est pas touchée, un code inconnu n'écrit rien.
     */
    public function testRecordCodeExchangeOfNotesTheConnectionOfTheCodeOnce(): void
    {
        $connection = $this->grantConnection();
        $other = McpConnection::grant($connection->account(), $connection->client(), self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($other);
        $codeHash = hash('sha256', 'clear-code');
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, $codeHash, new DateTimeImmutable('+10 minutes')));
        $this->authorizationCodes()->add(new McpAuthorizationCode($other, hash('sha256', 'other-code'), new DateTimeImmutable('+10 minutes')));
        $first = new DateTimeImmutable('2026-10-23T10:00:00+02:00');

        $this->connections()->recordCodeExchangeOf($codeHash, $first);
        $this->connections()->recordCodeExchangeOf($codeHash, new DateTimeImmutable('2026-10-23T11:00:00+02:00'));
        $this->connections()->recordCodeExchangeOf(hash('sha256', 'unknown-code'), $first);
        $this->em->clear();

        self::assertSame($first->getTimestamp(), $this->connections()->findOneById($connection->getId())?->codeExchangedAt()?->getTimestamp());
        self::assertNull($this->connections()->findOneById($other->getId())?->codeExchangedAt(), 'Le code d\'une autre connexion a noté la sienne.');
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
        self::assertTrue($connection->getId()->equals($revoked->connectionId()));
        self::assertSame($account->getId()->toRfc4122(), $revoked->accountId());
        self::assertSame('https://client.example.test/metadata.json', $revoked->clientId());
        self::assertSame(RevocationCause::Client, $revoked->cause());
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
     * La révocation par la personne (#650) : une instruction qui désigne la
     * connexion **et** son compte. Le compte d'un autre ne touche pas la
     * ligne (`null`, base intacte) — le filtre par compte est ce qui fait
     * qu'on ne révoque que les siennes ; le bon compte la révoque, avec
     * l'instant et la cause donnés.
     */
    public function testRevokeActiveOfAccountTouchesOnlyThatAccountsRow(): void
    {
        $owner = $this->persistAccount('jane');
        $stranger = $this->persistAccount('john');
        $connection = McpConnection::grant($owner, $this->registerClient('https://client.example.test/metadata.json'), self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($connection);
        $at = new DateTimeImmutable('2026-10-28T10:00:00+01:00');

        $byStranger = $this->connections()->revokeActiveOfAccount($connection->getId(), $stranger->getId(), RevocationCause::Person, $at);

        self::assertNull($byStranger);
        self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($connection));

        $byOwner = $this->connections()->revokeActiveOfAccount($connection->getId(), $owner->getId(), RevocationCause::Person, $at);

        self::assertInstanceOf(RevokedConnection::class, $byOwner);
        self::assertSame($owner->getId()->toRfc4122(), $byOwner->accountId());
        self::assertSame('https://client.example.test/metadata.json', $byOwner->clientId());
        self::assertSame(RevocationCause::Person, $byOwner->cause());
        self::assertSame(['revoked_at' => $at->getTimestamp(), 'revocation_cause' => 'person'], $this->revocationRow($connection));
    }

    /** Déjà révoquée : la première révocation est gardée, rien n'est rendu à auditer. */
    public function testRevokeActiveOfAccountKeepsTheFirstRevocation(): void
    {
        $connection = $this->grantConnection();
        $accountId = $connection->account()->getId();
        $first = new DateTimeImmutable('2026-10-23T10:00:00+02:00');
        $this->connections()->revokeActive($connection->getId(), RevocationCause::Client, $first);

        $second = $this->connections()->revokeActiveOfAccount($connection->getId(), $accountId, RevocationCause::Person, new DateTimeImmutable('2026-10-23T11:00:00+02:00'));

        self::assertNull($second);
        self::assertSame(['revoked_at' => $first->getTimestamp(), 'revocation_cause' => 'client'], $this->revocationRow($connection));
    }

    /** `belongsToAccount()` répond pour une connexion révoquée comme pour une active, et pour le seul compte propriétaire. */
    public function testBelongsToAccountTellsOwnershipWhateverTheState(): void
    {
        $connection = $this->grantConnection();
        $owner = $connection->account()->getId();
        $this->connections()->revokeActive($connection->getId(), RevocationCause::Client, new DateTimeImmutable());

        self::assertTrue($this->connections()->belongsToAccount($connection->getId(), $owner));
        self::assertFalse($this->connections()->belongsToAccount($connection->getId(), Uuid::v7()));
        self::assertFalse($this->connections()->belongsToAccount(Uuid::v7(), $owner));
    }

    /**
     * Toutes les connexions actives d'un compte (#650, mot de passe changé,
     * palier retiré), dans la transaction de l'appelant : celles du compte
     * seulement, jamais celle d'un autre ; une déjà révoquée garde son instant
     * et sa cause, et n'est pas rendue (donc jamais auditée une seconde fois).
     */
    public function testRevokeAllActiveOfAccountLeavesRevokedAndForeignRowsAlone(): void
    {
        $owner = $this->persistAccount('jane');
        $stranger = $this->persistAccount('john');
        $first = McpConnection::grant($owner, $this->registerClient('https://client.example.test/one.json'), self::SCOPE, new DateTimeImmutable());
        $second = McpConnection::grant($owner, $this->registerClient('https://client.example.test/two.json'), self::SCOPE, new DateTimeImmutable());
        $alreadyRevoked = McpConnection::grant($owner, $this->registerClient('https://client.example.test/three.json'), self::SCOPE, new DateTimeImmutable());
        $foreign = McpConnection::grant($stranger, $this->registerClient('https://client.example.test/four.json'), self::SCOPE, new DateTimeImmutable());
        foreach ([$first, $second, $alreadyRevoked, $foreign] as $connection) {
            $this->connections()->add($connection);
        }
        $earlier = new DateTimeImmutable('2026-10-23T10:00:00+02:00');
        $this->connections()->revokeActive($alreadyRevoked->getId(), RevocationCause::Client, $earlier);
        $at = new DateTimeImmutable('2026-10-28T10:00:00+01:00');

        $database = $this->em->getConnection();
        $database->beginTransaction();
        try {
            $revoked = $this->connections()->revokeAllActiveOfAccount($owner->getId(), RevocationCause::PasswordChanged, $at);
        } finally {
            $database->commit();
        }

        $ids = array_map(static fn (RevokedConnection $connection): string => $connection->connectionId()->toRfc4122(), $revoked);
        $expected = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        sort($ids);
        sort($expected);
        self::assertSame($expected, $ids, 'Seules les deux actives du compte sont rendues.');
        foreach ($revoked as $connection) {
            self::assertSame(RevocationCause::PasswordChanged, $connection->cause());
            self::assertSame($owner->getId()->toRfc4122(), $connection->accountId());
        }
        self::assertSame(['revoked_at' => $at->getTimestamp(), 'revocation_cause' => 'password-changed'], $this->revocationRow($first));
        self::assertSame(['revoked_at' => $at->getTimestamp(), 'revocation_cause' => 'password-changed'], $this->revocationRow($second));
        self::assertSame(['revoked_at' => $earlier->getTimestamp(), 'revocation_cause' => 'client'], $this->revocationRow($alreadyRevoked));
        self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($foreign));
    }

    /** Un compte sans connexion active : rien à révoquer, une liste vide. */
    public function testRevokeAllActiveOfAccountWithoutConnectionsRevokesNothing(): void
    {
        $owner = $this->persistAccount('jane');

        $database = $this->em->getConnection();
        $database->beginTransaction();
        try {
            $revoked = $this->connections()->revokeAllActiveOfAccount($owner->getId(), RevocationCause::TrustedTierWithdrawn, new DateTimeImmutable());
        } finally {
            $database->commit();
        }

        self::assertSame([], $revoked);
    }

    /**
     * Hors transaction, l'instruction seule serait validée avant le reste de
     * l'opération de l'appelant (le mot de passe, le rôle) : la garde refuse,
     * sous une exception nommée, et rien n'est écrit.
     */
    public function testRevokeAllActiveOfAccountRefusesOutsideATransaction(): void
    {
        $connection = $this->grantConnection();

        try {
            $this->connections()->revokeAllActiveOfAccount($connection->account()->getId(), RevocationCause::PasswordChanged, new DateTimeImmutable());
            self::fail('La révocation de tout un compte hors transaction aurait dû être refusée.');
        } catch (RevocationOutsideTransactionException) {
            self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($connection));
        }
    }

    /**
     * Le verrou des actives d'un client (#652) : celles de ce client seulement
     * — ni une révoquée, ni celle d'un autre client —, dans l'ordre des
     * identifiants, chacune désignée comme `RevokedConnectionAudit::record()`
     * la consomme (compte, client public). Aucune ligne n'est écrite.
     */
    public function testLockActiveOfClientReturnsOnlyItsActiveConnectionsInIdOrder(): void
    {
        $client = $this->registerClient('https://client.example.test/one.json');
        $other = $this->registerClient('https://client.example.test/two.json');
        $jane = $this->persistAccount('jane');
        $john = $this->persistAccount('john');
        $first = McpConnection::grant($jane, $client, self::SCOPE, new DateTimeImmutable());
        $second = McpConnection::grant($john, $client, self::SCOPE, new DateTimeImmutable());
        $revoked = McpConnection::grant($jane, $client, self::SCOPE, new DateTimeImmutable());
        $foreign = McpConnection::grant($jane, $other, self::SCOPE, new DateTimeImmutable());
        foreach ([$second, $first, $revoked, $foreign] as $connection) {
            $this->connections()->add($connection);
        }
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Client, new DateTimeImmutable());

        $database = $this->em->getConnection();
        $database->beginTransaction();
        try {
            $locked = $this->connections()->lockActiveOfClient($client->getId());
        } finally {
            $database->commit();
        }

        $expected = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, array_map(static fn (ConnectionReference $connection): string => $connection->connectionId()->toRfc4122(), $locked), 'Les deux actives du client, par identifiant croissant.');
        foreach ($locked as $connection) {
            self::assertSame('https://client.example.test/one.json', $connection->clientId());
        }
        $accountOf = [$first->getId()->toRfc4122() => $jane->getId()->toRfc4122(), $second->getId()->toRfc4122() => $john->getId()->toRfc4122()];
        foreach ($locked as $connection) {
            self::assertSame($accountOf[$connection->connectionId()->toRfc4122()], $connection->accountId());
        }
        self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($first), 'Le verrou ne révoque rien.');
    }

    /**
     * Hors transaction, `FOR UPDATE` ne tiendrait que le temps de
     * l'instruction : le verrou n'existerait pas pour l'écriture qui le suit.
     * Même garde, même exception que `revokeAllActiveOfAccount()`.
     */
    public function testLockActiveOfClientOutsideATransactionIsRefused(): void
    {
        $connection = $this->grantConnection();
        $client = $connection->client()->getId();

        $this->expectException(RevocationOutsideTransactionException::class);
        // Le message cite l'UUID du client (RFC 4122), mis en forme par la fabrique.
        $this->expectExceptionMessage($client->toRfc4122());
        $this->connections()->lockActiveOfClient($client);
    }

    /**
     * Le décompte de la liste des clients (#652) : les actives de chaque
     * client, **en une seule requête** (jamais une par client), une révoquée
     * ne comptant pas et un client sans connexion active étant absent du
     * tableau (l'appelant lit `?? 0`). Clé : l'UUID RFC 4122 de la ligne
     * `mcp_client`.
     */
    public function testCountActiveByClientCountsActiveConnectionsInOneQuery(): void
    {
        $busy = $this->registerClient('https://client.example.test/busy.json');
        $single = $this->registerClient('https://client.example.test/single.json');
        $idle = $this->registerClient('https://client.example.test/idle.json');
        $onlyRevoked = $this->registerClient('https://client.example.test/revoked.json');
        $jane = $this->persistAccount('jane');
        $john = $this->persistAccount('john');
        $revoked = McpConnection::grant($jane, $onlyRevoked, self::SCOPE, new DateTimeImmutable());
        foreach ([
            McpConnection::grant($jane, $busy, self::SCOPE, new DateTimeImmutable()),
            McpConnection::grant($john, $busy, self::SCOPE, new DateTimeImmutable()),
            McpConnection::grant($jane, $single, self::SCOPE, new DateTimeImmutable()),
            $revoked,
        ] as $connection) {
            $this->connections()->add($connection);
        }
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Client, new DateTimeImmutable());

        $queries = $this->queriesDuring(fn (): array => $this->connections()->countActiveByClient());

        self::assertSame(1, $queries['count'], 'Une seule requête pour tous les clients.');
        // L'ordre d'un GROUP BY n'est pas garanti : les deux tableaux sont triés par clé.
        $counts = $queries['result'];
        $expected = [$busy->getId()->toRfc4122() => 2, $single->getId()->toRfc4122() => 1];
        ksort($counts);
        ksort($expected);
        self::assertSame($expected, $counts, 'Un client sans connexion active, ou dont toutes sont révoquées, est absent.');
        self::assertArrayNotHasKey($idle->getId()->toRfc4122(), $counts);
    }

    /**
     * Le décompte du détail d'un client (#652) : les connexions actives **de ce
     * client seul**, en une requête. Une révoquée ne compte pas, ni celle d'un
     * autre client ; un client sans connexion active vaut 0 (l'entier, pas
     * une clé absente : il n'y a pas de tableau à lire `?? 0`).
     */
    public function testCountActiveOfClientCountsOnlyTheActiveConnectionsOfThatClient(): void
    {
        $busy = $this->registerClient('https://client.example.test/busy.json');
        $other = $this->registerClient('https://client.example.test/other.json');
        $idle = $this->registerClient('https://client.example.test/idle.json');
        $jane = $this->persistAccount('jane');
        $john = $this->persistAccount('john');
        $revoked = McpConnection::grant($john, $busy, self::SCOPE, new DateTimeImmutable());
        foreach ([
            McpConnection::grant($jane, $busy, self::SCOPE, new DateTimeImmutable()),
            McpConnection::grant($john, $busy, self::SCOPE, new DateTimeImmutable()),
            $revoked,
            McpConnection::grant($jane, $other, self::SCOPE, new DateTimeImmutable()),
        ] as $connection) {
            $this->connections()->add($connection);
        }
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Client, new DateTimeImmutable());

        $queries = $this->queriesDuring(fn (): int => $this->connections()->countActiveOfClient($busy->getId()));

        self::assertSame(1, $queries['count'], 'Une seule requête.');
        self::assertSame(2, $queries['result'], 'Deux actives ; la révoquée et celle de l\'autre client ne comptent pas.');
        self::assertSame(1, $this->connections()->countActiveOfClient($other->getId()));
        self::assertSame(0, $this->connections()->countActiveOfClient($idle->getId()), 'Aucune connexion : 0.');
        self::assertSame(0, $this->connections()->countActiveOfClient(Uuid::v7()), 'Un client inconnu : 0 aussi, la lecture ne juge pas son existence.');
    }

    /**
     * Un nouvel accord remplace l'accord actif du même couple compte/client
     * (#649, décision 2) : `revokeOtherActiveOf()` révoque, en une
     * instruction, toute autre connexion active de ce couple — et rien
     * d'autre : ni la connexion gardée, ni celle d'un autre client, ni celle
     * d'un autre compte, ni une déjà révoquée, qui garde sa première
     * révocation. Elle rend de quoi auditer chaque connexion remplacée.
     */
    public function testRevokeOtherActiveOfRevokesTheOtherActiveGrantsOfThePairOnly(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $otherClient = $this->registerClient('https://other-client.example.test/metadata.json');
        $previous = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        $older = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        $alreadyRevoked = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        $ofAnotherClient = McpConnection::grant($account, $otherClient, self::SCOPE, new DateTimeImmutable());
        $ofAnotherAccount = McpConnection::grant($this->persistAccount('john'), $client, self::SCOPE, new DateTimeImmutable());
        $kept = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        foreach ([$previous, $older, $alreadyRevoked, $ofAnotherClient, $ofAnotherAccount, $kept] as $connection) {
            $this->connections()->add($connection);
        }
        $firstRevocation = new DateTimeImmutable('2026-10-23T10:00:00+02:00');
        $this->connections()->revokeActive($alreadyRevoked->getId(), RevocationCause::Client, $firstRevocation);
        $at = new DateTimeImmutable('2026-10-25T02:30:00+02:00');

        $revoked = $this->connections()->revokeOtherActiveOf($kept, RevocationCause::Superseded, $at);

        $revokedIds = array_map(static fn (RevokedConnection $connection): string => $connection->connectionId()->toRfc4122(), $revoked);
        sort($revokedIds);
        $expectedIds = [$previous->getId()->toRfc4122(), $older->getId()->toRfc4122()];
        sort($expectedIds);
        self::assertSame($expectedIds, $revokedIds);
        foreach ($revoked as $connection) {
            self::assertSame($account->getId()->toRfc4122(), $connection->accountId());
            self::assertSame('https://client.example.test/metadata.json', $connection->clientId());
            self::assertSame(RevocationCause::Superseded, $connection->cause());
        }
        foreach ([$previous, $older] as $connection) {
            self::assertSame(['revoked_at' => $at->getTimestamp(), 'revocation_cause' => 'superseded'], $this->revocationRow($connection));
        }
        self::assertSame(['revoked_at' => $firstRevocation->getTimestamp(), 'revocation_cause' => 'client'], $this->revocationRow($alreadyRevoked));
        foreach ([$ofAnotherClient, $ofAnotherAccount, $kept] as $connection) {
            self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($connection));
        }
    }

    /** Sans autre accord actif du couple, rien n'est révoqué ni rendu. */
    public function testRevokeOtherActiveOfAloneRevokesNothing(): void
    {
        $kept = $this->grantConnection();

        self::assertSame([], $this->connections()->revokeOtherActiveOf($kept, RevocationCause::Superseded, new DateTimeImmutable()));
        self::assertSame(['revoked_at' => null, 'revocation_cause' => null], $this->revocationRow($kept));
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
        self::assertSame(RevocationCause::CodeReplay, $revoked?->cause());
        self::assertSame('code-replay', $this->revocationRow($connection)['revocation_cause']);
    }

    /**
     * La référence d'une connexion se lit après un ROLLBACK qui a fermé
     * l'EntityManager (#725) : l'audit d'un refus au point de jeton. Révoquée
     * ou non, une connexion qui existe est désignée ; une connexion disparue
     * ne l'est pas.
     */
    public function testReferenceOfNamesTheAccountAndClientOnAClosedEntityManager(): void
    {
        $account = $this->persistAccount('jane');
        $connection = McpConnection::grant($account, $this->registerClient('https://client.example.test/metadata.json'), self::SCOPE, new DateTimeImmutable());
        $this->connections()->add($connection);
        $this->connections()->revokeActive($connection->getId(), RevocationCause::Client, new DateTimeImmutable());
        $this->em->close();

        $reference = $this->connections()->referenceOf($connection->getId());

        self::assertFalse($this->em->isOpen());
        self::assertNotNull($reference);
        self::assertTrue($connection->getId()->equals($reference->connectionId()));
        self::assertSame($account->getId()->toRfc4122(), $reference->accountId());
        self::assertSame('https://client.example.test/metadata.json', $reference->clientId());
        self::assertNull($this->connections()->referenceOf(Uuid::v7()));
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
        yield 'un instant sans cause' => ['UPDATE mcp_connection SET revoked_at = now() WHERE id = :id'];
        yield 'une cause sans instant' => ["UPDATE mcp_connection SET revocation_cause = 'client' WHERE id = :id"];
    }

    #[DataProvider('halfRevocations')]
    public function testTheDatabaseRefusesARevocationWithoutCause(string $update): void
    {
        $connection = $this->grantConnection();

        try {
            $this->em->getConnection()->executeStatement($update, ['id' => $connection->getId()], ['id' => UuidType::NAME]);
            self::fail('La base aurait dû refuser une révocation à moitié écrite.');
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString('chk_mcp_connection_revocation_cause', $exception->getMessage());
        }
    }

    /**
     * La base n'admet que les causes de {@see RevocationCause}
     * (`chk_mcp_connection_revocation_cause_value`, relecture du lot B de
     * #649) : une valeur inconnue en base ferait lever `ValueError` à
     * l'hydratation de l'énumération — un 500 à la place du 401 sur chaque
     * lecture de la connexion. Chaque cas de l'énumération passe, une valeur
     * hors d'elle est refusée sous le nom de la contrainte ; la cause `person`
     * de #650 élargit la contrainte par migration **avant** d'être écrite —
     * ajoutée à l'énumération seule, ce test la voit refusée.
     */
    public function testTheDatabaseAdmitsEveryRevocationCauseAndRefusesAnUnknownOne(): void
    {
        $id = $this->grantConnection()->getId();
        $connection = $this->em->getConnection();

        foreach (RevocationCause::cases() as $cause) {
            $connection->beginTransaction();
            try {
                $written = $connection->executeStatement(
                    'UPDATE mcp_connection SET revoked_at = now(), revocation_cause = :cause WHERE id = :id',
                    ['cause' => $cause->value, 'id' => $id],
                    ['id' => UuidType::NAME],
                );
                self::assertSame(1, $written, \sprintf('La cause « %s » est refusée par la base.', $cause->value));
            } finally {
                $connection->rollBack();
            }
        }

        try {
            $connection->executeStatement("UPDATE mcp_connection SET revoked_at = now(), revocation_cause = 'unknown-cause' WHERE id = :id", ['id' => $id], ['id' => UuidType::NAME]);
            self::fail('La base aurait dû refuser une cause de révocation inconnue.');
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString('chk_mcp_connection_revocation_cause_value', $exception->getMessage());
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

    /**
     * « Mes connexions MCP » (#650, R3), la moitié non révoquée : **toutes** les
     * connexions du compte dont `revoked_at` est nul — aussi anciennes
     * soient-elles —, jamais une révoquée ni celle d'un autre compte, la plus
     * récemment octroyée d'abord. Le client arrive avec elles, par jointure.
     */
    public function testFindNotRevokedOfAccountListsEveryNonRevokedConnectionNewestFirstWithItsClient(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $otherClient = $this->registerClient('https://other-client.example.test/metadata.json');
        $oldest = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2024-01-01T10:00:00+01:00'));
        $revoked = McpConnection::grant($account, $otherClient, self::SCOPE, new DateTimeImmutable('2026-10-10T10:00:00+02:00'));
        $newest = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-10-20T10:00:00+02:00'));
        $ofAnotherAccount = McpConnection::grant($this->persistAccount('john'), $client, self::SCOPE, new DateTimeImmutable('2026-10-15T10:00:00+02:00'));
        foreach ([$oldest, $revoked, $newest, $ofAnotherAccount] as $connection) {
            $this->connections()->add($connection);
        }
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Client, new DateTimeImmutable('2026-10-11T10:00:00+02:00'));
        $this->em->clear();

        $found = $this->connections()->findNotRevokedOfAccount($account->getId());

        self::assertSame(
            [$newest->getId()->toRfc4122(), $oldest->getId()->toRfc4122()],
            array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $found),
        );
        foreach ($found as $connection) {
            self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($connection->client()), 'Le client est joint, pas chargé à la demande.');
        }
        self::assertSame([], $this->connections()->findNotRevokedOfAccount(Uuid::v7()));
    }

    /**
     * La moitié terminée (`revoked_at` posé, quelle qu'en soit la cause) :
     * les `$limit` dernièrement terminées, la plus récente d'abord, jamais
     * une non révoquée ni celle d'un autre compte ; plus courte que la limite,
     * elle rend tout ce qu'il y a.
     */
    public function testFindTerminatedOfAccountReturnsTheMostRecentlyTerminatedOnesUpToTheLimit(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $terminated = [];
        foreach (range(1, 5) as $day) {
            $terminated[$day] = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable(\sprintf('2026-10-%02dT10:00:00+02:00', $day)));
        }
        $active = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-10-20T10:00:00+02:00'));
        $foreign = McpConnection::grant($this->persistAccount('john'), $client, self::SCOPE, new DateTimeImmutable('2026-10-21T10:00:00+02:00'));
        foreach ([...$terminated, $active, $foreign] as $connection) {
            $this->connections()->add($connection);
        }
        foreach ([...$terminated, $foreign] as $day => $connection) {
            $this->connections()->revokeActive($connection->getId(), RevocationCause::Expired, new DateTimeImmutable(\sprintf('2026-10-25T10:%02d:00+02:00', $day)));
        }
        $this->em->clear();

        $found = $this->connections()->findTerminatedOfAccount($account->getId(), 3);

        self::assertSame(
            [$terminated[5]->getId()->toRfc4122(), $terminated[4]->getId()->toRfc4122(), $terminated[3]->getId()->toRfc4122()],
            array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $found),
        );
        foreach ($found as $connection) {
            self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($connection->client()), 'Le client est joint, pas chargé à la demande.');
        }
        self::assertCount(5, $this->connections()->findTerminatedOfAccount($account->getId(), 10));
        self::assertSame([], $this->connections()->findTerminatedOfAccount(Uuid::v7(), 10));
    }

    /**
     * CR2 de la seconde relecture : la limite garde les terminées **dernièrement
     * terminées**, pas les dernièrement octroyées. Une connexion octroyée il y a
     * cinq mois que la personne vient de révoquer est la première qu'elle
     * cherche des yeux ; triée par octroi, cinquante terminées plus récentes la
     * chassaient de la liste.
     */
    public function testFindTerminatedOfAccountKeepsTheLatestTerminatedNotTheLatestGranted(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $old = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-05-01T10:00:00+02:00'));
        $this->connections()->add($old);
        $others = [];
        foreach (range(1, 50) as $minute) {
            $other = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable(\sprintf('2026-10-20T10:%02d:00+02:00', $minute)));
            $this->connections()->add($other);
            $this->connections()->revokeActive($other->getId(), RevocationCause::Expired, new DateTimeImmutable('2026-10-21T10:00:00+02:00'));
            $others[] = $other;
        }
        // La plus ancienne octroyée est la dernière terminée : on la révoque maintenant.
        $this->connections()->revokeActive($old->getId(), RevocationCause::Person, new DateTimeImmutable('2026-10-28T10:00:00+01:00'));
        $this->em->clear();

        $found = $this->connections()->findTerminatedOfAccount($account->getId(), 50);

        $ids = array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $found);
        self::assertCount(50, $ids);
        self::assertSame($old->getId()->toRfc4122(), $ids[0], 'La dernière terminée ouvre la liste, quelle que soit sa date d\'octroi.');
        self::assertNotContains($others[0]->getId()->toRfc4122(), $ids, 'C\'est la plus ancienne des terminées antérieures qui sort.');
    }

    /** Deux octrois de la même seconde se rangent par identifiant décroissant : l'ordre ne dépend pas du plan de la requête. */
    public function testFindNotRevokedOfAccountBreaksTiesByDescendingId(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $grantedAt = new DateTimeImmutable('2026-10-20T10:00:00+02:00');
        $first = McpConnection::grant($account, $client, self::SCOPE, $grantedAt);
        $second = McpConnection::grant($account, $client, self::SCOPE, $grantedAt);
        $this->connections()->add($first);
        $this->connections()->add($second);
        $this->em->clear();

        $ids = array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $this->connections()->findNotRevokedOfAccount($account->getId()));

        $expected = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        rsort($expected);
        self::assertSame($expected, $ids);
    }

    /**
     * Même départage pour la moitié terminée, **avant** la coupe : deux
     * révoquées de la même seconde, la limite de un garde celle d'identifiant
     * le plus grand, toujours la même.
     */
    public function testFindTerminatedOfAccountBreaksTiesByDescendingIdBeforeTheLimit(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $grantedAt = new DateTimeImmutable('2026-10-20T10:00:00+02:00');
        $first = McpConnection::grant($account, $client, self::SCOPE, $grantedAt);
        $second = McpConnection::grant($account, $client, self::SCOPE, $grantedAt);
        foreach ([$first, $second] as $connection) {
            $this->connections()->add($connection);
            $this->connections()->revokeActive($connection->getId(), RevocationCause::Person, new DateTimeImmutable('2026-10-25T10:00:00+02:00'));
        }
        $this->em->clear();

        $found = $this->connections()->findTerminatedOfAccount($account->getId(), 1);

        $highest = max($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertSame([$highest], array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $found));
    }

    /**
     * Les listes de l'administration (#652, T13) ne se limitent à aucun compte
     * ni à aucun client : deux comptes, deux clients, et chaque moitié rend
     * les connexions de tous, la plus récemment octroyée d'abord. La moitié
     * non révoquée ne rend jamais une révoquée, la terminée jamais une active.
     */
    public function testAdministrationListsSpanEveryAccount(): void
    {
        $jane = $this->persistAccount('jane');
        $john = $this->persistAccount('john');
        $first = $this->registerClient('https://client.example.test/metadata.json');
        $second = $this->registerClient('https://other-client.example.test/metadata.json');
        $janeOnFirst = McpConnection::grant($jane, $first, self::SCOPE, new DateTimeImmutable('2026-10-01T10:00:00+02:00'));
        $johnOnSecond = McpConnection::grant($john, $second, self::SCOPE, new DateTimeImmutable('2026-10-03T10:00:00+02:00'));
        $janeRevoked = McpConnection::grant($jane, $second, self::SCOPE, new DateTimeImmutable('2026-10-02T10:00:00+02:00'));
        $johnRevoked = McpConnection::grant($john, $first, self::SCOPE, new DateTimeImmutable('2026-10-04T10:00:00+02:00'));
        foreach ([$janeOnFirst, $johnOnSecond, $janeRevoked, $johnRevoked] as $connection) {
            $this->connections()->add($connection);
        }
        foreach ([$janeRevoked, $johnRevoked] as $connection) {
            $this->connections()->revokeActive($connection->getId(), RevocationCause::Client, new DateTimeImmutable('2026-10-10T10:00:00+02:00'));
        }
        $this->em->clear();

        self::assertSame(
            [$johnOnSecond->getId()->toRfc4122(), $janeOnFirst->getId()->toRfc4122()],
            $this->idsOf($this->connections()->findNotRevokedForAdministration($this->abandonedThreshold(new DateTimeImmutable('2026-09-01T00:00:00+02:00')))),
        );
        self::assertSame(
            [$johnRevoked->getId()->toRfc4122(), $janeRevoked->getId()->toRfc4122()],
            $this->idsOf($this->connections()->findTerminatedForAdministration(10)),
        );
    }

    /** La moitié terminée de l'administration est bornée par `$limit` : les plus récentes d'abord, les plus anciennes sortent. */
    public function testTerminatedForAdministrationIsBoundedAndMostRecentFirst(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $terminated = [];
        foreach (range(1, 3) as $day) {
            $terminated[$day] = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable(\sprintf('2026-10-%02dT10:00:00+02:00', $day)));
            $this->connections()->add($terminated[$day]);
            $this->connections()->revokeActive($terminated[$day]->getId(), RevocationCause::Client, new DateTimeImmutable(\sprintf('2026-10-25T10:%02d:00+02:00', $day)));
        }
        $this->em->clear();

        $found = $this->connections()->findTerminatedForAdministration(2);

        self::assertSame(
            [$terminated[3]->getId()->toRfc4122(), $terminated[2]->getId()->toRfc4122()],
            array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $found),
        );
    }

    /**
     * Spec 0007, S4 : la limite garde les terminées **dernièrement terminées**,
     * pas les dernièrement octroyées (même invariant que
     * {@see self::testFindTerminatedOfAccountKeepsTheLatestTerminatedNotTheLatestGranted()}).
     * Ici l'ordre d'octroi et l'ordre de révocation sont inverses : la plus
     * anciennement octroyée est la plus récemment révoquée. Triée par octroi,
     * la borne la chasserait de la liste au profit d'une terminée plus ancienne.
     * L'ordre rendu est celui de l'instant de fin ; l'ordre d'affichage par
     * octroi revient au service.
     */
    public function testTerminatedForAdministrationKeepsTheMostRecentlyTerminated(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $grantedLongAgo = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-05-01T10:00:00+02:00'));
        $grantedMiddle = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-09-01T10:00:00+02:00'));
        $grantedRecently = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-10-01T10:00:00+02:00'));
        // Révoquées dans l'ordre inverse de l'octroi : la plus ancienne octroyée en dernier.
        $revocations = [
            [$grantedRecently, '2026-10-20T10:00:00+02:00'],
            [$grantedMiddle, '2026-10-25T10:00:00+02:00'],
            [$grantedLongAgo, '2026-10-28T10:00:00+01:00'],
        ];
        foreach ($revocations as [$connection, $revokedAt]) {
            $this->connections()->add($connection);
            $this->connections()->revokeActive($connection->getId(), RevocationCause::Person, new DateTimeImmutable($revokedAt));
        }
        $this->em->clear();

        $found = $this->connections()->findTerminatedForAdministration(2);

        self::assertSame(
            [$grantedLongAgo->getId()->toRfc4122(), $grantedMiddle->getId()->toRfc4122()],
            $this->idsOf($found),
            'Les deux dernièrement terminées, la plus récente d\'abord, quelle que soit leur date d\'octroi.',
        );
    }

    /**
     * Un accord abandonné (#652) quitte les non révoquées **en SQL**, à
     * l'instant où le délai des non échangées est atteint : à 59 minutes il est
     * encore vivant, à 61 il passe dans la requête bornée à part. Un accord dont
     * le code a été échangé n'est jamais filtré par ce seuil, quel que soit son
     * âge. Le seuil vient de {@see ConnectionLifetimes}, jamais d'une durée
     * recopiée ici.
     */
    public function testAnAbandonedGrantLeavesTheNotRevokedAndTheExchangedOneNever(): void
    {
        $now = new DateTimeImmutable('2026-11-20T12:00:00+01:00');
        $threshold = $this->abandonedThreshold($now);
        $unit = $this->lifetimesFromContainer()->unexchanged();
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $young = McpConnection::grant($account, $client, self::SCOPE, $threshold->add(new DateInterval('PT1M')));
        $abandoned = McpConnection::grant($account, $client, self::SCOPE, $threshold->sub(new DateInterval('PT1M')));
        $exchangedOld = McpConnection::grant($account, $client, self::SCOPE, $now->sub($unit)->sub($unit)->sub($unit));
        foreach ([$young, $abandoned, $exchangedOld] as $connection) {
            $this->connections()->add($connection);
        }
        $this->exchangeCodeOf($exchangedOld, $exchangedOld->grantedAt()->add(new DateInterval('PT1M')));
        $this->em->clear();

        $alive = $this->idsOf($this->connections()->findNotRevokedForAdministration($threshold));
        $apart = $this->idsOf($this->connections()->findAbandonedForAdministration($threshold, 10));

        self::assertEqualsCanonicalizing([$young->getId()->toRfc4122(), $exchangedOld->getId()->toRfc4122()], $alive);
        self::assertSame([$abandoned->getId()->toRfc4122()], $apart, 'Ni la jeune ni l\'échangée, même très ancienne.');
    }

    /** Le seuil est inclus : un octroi exactement à `maintenant − durée` est abandonné, comme `stateOf()` le dit. */
    public function testAGrantExactlyAtTheThresholdIsAbandoned(): void
    {
        $threshold = $this->abandonedThreshold(new DateTimeImmutable('2026-11-20T12:00:00+01:00'));
        $connection = McpConnection::grant($this->persistAccount('jane'), $this->registerClient('https://client.example.test/metadata.json'), self::SCOPE, $threshold);
        $this->connections()->add($connection);
        $this->em->clear();

        self::assertSame([], $this->connections()->findNotRevokedForAdministration($threshold));
        self::assertSame([$connection->getId()->toRfc4122()], $this->idsOf($this->connections()->findAbandonedForAdministration($threshold, 10)));
    }

    /**
     * Un abandonné que la purge a marqué expiré reste dans les abandonnées, rangé à
     * son octroi (sa fin vaut octroi + délai du code), jamais dans les terminées
     * par une autre cause : son `revoked_at` est l'instant de la purge, pas sa fin.
     */
    public function testAnAbandonedGrantMarkedExpiredStaysWithTheNeverExchanged(): void
    {
        $now = new DateTimeImmutable('2026-11-20T12:00:00+01:00');
        $threshold = $this->abandonedThreshold($now);
        $connection = McpConnection::grant($this->persistAccount('jane'), $this->registerClient('https://client.example.test/metadata.json'), self::SCOPE, $threshold->sub(new DateInterval('PT5M')));
        $this->connections()->add($connection);
        $this->connections()->revokeActive($connection->getId(), RevocationCause::Expired, $now);
        $this->em->clear();

        self::assertSame([$connection->getId()->toRfc4122()], $this->idsOf($this->connections()->findAbandonedForAdministration($threshold, 10)));
        self::assertSame([], $this->connections()->findNotRevokedForAdministration($threshold));
        self::assertSame([], $this->connections()->findTerminatedForAdministration(10));
        self::assertSame([], $this->connections()->findExchangedExpiredForAdministration(null, 10));
    }

    /**
     * Les trois lectures de la liste terminée se partagent les lignes sans en
     * perdre ni en doubler : révoquée par une personne (terminées), expirée
     * jamais échangée (abandonnées), expirée échangée (pages), vivante échangée
     * (ni l'une ni l'autre).
     */
    public function testTheThreeTerminatedReadsPartitionTheRows(): void
    {
        $now = new DateTimeImmutable('2026-11-20T12:00:00+01:00');
        $threshold = $this->abandonedThreshold($now);
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $person = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-09-01T10:00:00+02:00'));
        $neverExchanged = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-09-02T10:00:00+02:00'));
        $exchangedExpired = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-09-03T10:00:00+02:00'));
        $alive = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-11-19T10:00:00+01:00'));
        foreach ([$person, $neverExchanged, $exchangedExpired, $alive] as $connection) {
            $this->connections()->add($connection);
        }
        $this->exchangeCodeOf($exchangedExpired, $exchangedExpired->grantedAt()->add(new DateInterval('PT1M')));
        $this->exchangeCodeOf($alive, $alive->grantedAt()->add(new DateInterval('PT1M')));
        $this->connections()->revokeActive($person->getId(), RevocationCause::Person, new DateTimeImmutable('2026-10-05T10:00:00+02:00'));
        $this->connections()->revokeExpired([$neverExchanged->getId(), $exchangedExpired->getId()], new DateTimeImmutable('2026-11-19T03:00:00+01:00'));
        $this->em->clear();

        self::assertSame([$person->getId()->toRfc4122()], $this->idsOf($this->connections()->findTerminatedForAdministration(10)));
        self::assertSame([$neverExchanged->getId()->toRfc4122()], $this->idsOf($this->connections()->findAbandonedForAdministration($threshold, 10)));
        self::assertSame([$exchangedExpired->getId()->toRfc4122()], $this->idsOf($this->connections()->findExchangedExpiredForAdministration(null, 10)));
        self::assertSame([$alive->getId()->toRfc4122()], $this->idsOf($this->connections()->findNotRevokedForAdministration($threshold)));
    }

    /**
     * Les expirées échangées se lisent par activité décroissante : `last_used_at`,
     * à défaut `granted_at`, quel que soit l'instant de la purge (#652, S4d). Une
     * connexion jamais rafraîchie prend son octroi ; l'ordre de purge n'y est pour rien.
     */
    public function testExchangedExpiredForAdministrationIsOrderedByActivity(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        // Octroi le plus ancien mais utilisée le 10 ; jamais utilisée octroyée le 5 ; utilisée le 4, octroyée le 3.
        $usedLate = $this->expiredExchanged($account, $client, '2026-09-01T10:00:00+02:00', '2026-09-10T10:00:00+02:00');
        $neverUsed = $this->expiredExchanged($account, $client, '2026-09-05T10:00:00+02:00', null);
        $usedEarly = $this->expiredExchanged($account, $client, '2026-09-03T10:00:00+02:00', '2026-09-04T10:00:00+02:00');
        $this->em->clear();

        $ids = $this->idsOf($this->connections()->findExchangedExpiredForAdministration(null, 10));

        self::assertSame([$usedLate->getId()->toRfc4122(), $neverUsed->getId()->toRfc4122(), $usedEarly->getId()->toRfc4122()], $ids);
    }

    /**
     * Reprise stricte sur `(activité, id)` : aucune ligne omise ni doublée, y
     * compris quand un lot de lignes de même activité (une jamais utilisée
     * octroyée à l'instant où une autre a été utilisée) chevauche la limite de page.
     */
    public function testExchangedExpiredForAdministrationResumesExactlyAcrossTiedActivities(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $x = '2026-09-10T10:00:00+02:00';
        $y = '2026-09-08T10:00:00+02:00';
        $created = [
            $this->expiredExchanged($account, $client, $x, null),
            $this->expiredExchanged($account, $client, '2026-09-01T10:00:00+02:00', $x),
            $this->expiredExchanged($account, $client, $x, null),
            $this->expiredExchanged($account, $client, $y, null),
            $this->expiredExchanged($account, $client, '2026-09-02T10:00:00+02:00', $y),
            $this->expiredExchanged($account, $client, '2026-09-06T10:00:00+02:00', null),
            $this->expiredExchanged($account, $client, '2026-09-01T10:00:00+02:00', $x),
        ];
        $this->em->clear();

        // Attendu : activité décroissante, puis identifiant décroissant.
        $expected = array_map(static fn (McpConnection $c): array => [($c->lastUsedAt() ?? $c->grantedAt())->getTimestamp(), $c->getId()->toRfc4122()], $created);
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $expectedIds = array_column($expected, 1);

        $read = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->connections()->findExchangedExpiredForAdministration($cursor, 2);
            $read = [...$read, ...$this->idsOf($page)];
            $cursor = [] === $page ? null : ExchangedExpiredCursor::after($page[\count($page) - 1]);
            ++$pages;
        } while ([] !== $page && $pages < 10);

        // Quatre pages pleines ou partielles, puis une vide.
        self::assertSame($expectedIds, $read);
        self::assertCount(\count($created), array_unique($read));
        self::assertSame(5, $pages);
    }

    /** Les abandonnées sont bornées par `$limit` et rendues par octroi décroissant : la fin vaut octroi + une durée constante. */
    public function testAbandonedForAdministrationIsBoundedAndMostRecentlyGrantedFirst(): void
    {
        $threshold = $this->abandonedThreshold(new DateTimeImmutable('2026-11-20T12:00:00+01:00'));
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $abandoned = [];
        foreach ([1, 3, 2] as $hours) {
            $abandoned[$hours] = McpConnection::grant($account, $client, self::SCOPE, $threshold->sub(new DateInterval('PT'.$hours.'H')));
            $this->connections()->add($abandoned[$hours]);
        }
        $this->em->clear();

        self::assertSame(
            [$abandoned[1]->getId()->toRfc4122(), $abandoned[2]->getId()->toRfc4122()],
            $this->idsOf($this->connections()->findAbandonedForAdministration($threshold, 2)),
        );
    }

    /** Deux octrois au même instant : l'identifiant décroissant les départage. */
    public function testAbandonedForAdministrationBreaksTiesByDescendingId(): void
    {
        $threshold = $this->abandonedThreshold(new DateTimeImmutable('2026-11-20T12:00:00+01:00'));
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $grantedAt = $threshold->sub(new DateInterval('PT1H'));
        $first = McpConnection::grant($account, $client, self::SCOPE, $grantedAt);
        $second = McpConnection::grant($account, $client, self::SCOPE, $grantedAt);
        $this->connections()->add($first);
        $this->connections()->add($second);
        $this->em->clear();

        $ids = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        rsort($ids);
        self::assertSame($ids, $this->idsOf($this->connections()->findAbandonedForAdministration($threshold, 10)));
    }

    private function abandonedThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->lifetimesFromContainer()->abandonedBefore($now);
    }

    private function lifetimesFromContainer(): ConnectionLifetimes
    {
        return self::getContainer()->get(ConnectionLifetimes::class);
    }

    /** Une connexion échangée puis marquée expirée par la purge, d'octroi et de dernier usage donnés. */
    private function expiredExchanged(CpgUser $account, McpClient $client, string $grantedAt, ?string $lastUsedAt): McpConnection
    {
        $connection = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable($grantedAt));
        $this->connections()->add($connection);
        $this->exchangeCodeOf($connection, $connection->grantedAt()->add(new DateInterval('PT1M')));
        if (null !== $lastUsedAt) {
            $this->em->getConnection()->executeStatement(
                'UPDATE mcp_connection SET last_used_at = :at WHERE id = :id',
                ['at' => new DateTimeImmutable($lastUsedAt), 'id' => $connection->getId()],
                ['at' => Types::DATETIMETZ_IMMUTABLE, 'id' => UuidType::NAME],
            );
        }
        // Un même instant de purge pour toutes : l'ordre ne doit pas en dépendre.
        $this->connections()->revokeExpired([$connection->getId()], new DateTimeImmutable('2026-11-19T03:00:00+01:00'));
        $this->em->refresh($connection);

        return $connection;
    }

    /** Note l'échange du code de `$connection` à `$at`, par la voie de production. */
    private function exchangeCodeOf(McpConnection $connection, DateTimeImmutable $at): void
    {
        $codeHash = hash('sha256', 'code-'.$connection->getId()->toRfc4122());
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, $codeHash, $at->add(new DateInterval('PT10M'))));
        $this->connections()->recordCodeExchangeOf($codeHash, $at);
    }

    /**
     * Le client et le compte arrivent avec chaque connexion, par jointure :
     * lire leur nom ensuite n'envoie aucune requête de plus que la lecture
     * elle-même (une seule), sur une identity map vidée — sans quoi un client
     * déjà géré masquerait le chargement paresseux d'une N+1.
     */
    public function testAdministrationListsHydrateClientAndAccountWithoutFurtherQueries(): void
    {
        $first = $this->registerClient('https://client.example.test/metadata.json');
        $second = $this->registerClient('https://other-client.example.test/metadata.json');
        $revoked = McpConnection::grant($this->persistAccount('jane'), $first, self::SCOPE, new DateTimeImmutable('2026-10-01T10:00:00+02:00'));
        $active = McpConnection::grant($this->persistAccount('john'), $second, self::SCOPE, new DateTimeImmutable('2026-10-02T10:00:00+02:00'));
        $this->connections()->add($revoked);
        $this->connections()->add($active);
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Client, new DateTimeImmutable('2026-10-10T10:00:00+02:00'));
        $this->em->clear();

        $read = function (): array {
            $names = [];
            foreach ([...$this->connections()->findNotRevokedForAdministration($this->abandonedThreshold(new DateTimeImmutable('2026-09-01T00:00:00+02:00'))), ...$this->connections()->findTerminatedForAdministration(10)] as $connection) {
                $names[] = $connection->client()->getName().'/'.$connection->account()->getUsername();
            }

            return $names;
        };

        $queries = $this->queriesDuring($read);

        self::assertSame(2, $queries['count'], 'Une requête par moitié, aucune de plus pour le client ni pour le compte.');
        self::assertCount(2, $queries['result']);
    }

    /**
     * La lecture des expirées échangées (SQL natif + `ResultSetMappingBuilder`)
     * hydrate elle aussi le client et le compte par la même requête : une seule
     * requête, puis aucune de plus en lisant leur nom, sur une identity map vidée
     * (#652). Une jointure mal déclarée dans le mappage laisserait des proxys
     * que la lecture du nom chargerait un à un.
     */
    public function testExchangedExpiredReadHydratesClientAndAccountWithoutFurtherQueries(): void
    {
        // Deux clients de noms distincts : sans cela, une jointure croisée
        // (le client de l'autre connexion) passerait inaperçue.
        $first = $this->registerClient('https://client.example.test/metadata.json', 'Client Jane');
        $second = $this->registerClient('https://other-client.example.test/metadata.json', 'Client John');
        $this->expiredExchanged($this->persistAccount('jane'), $first, '2026-09-01T10:00:00+02:00', null);
        $this->expiredExchanged($this->persistAccount('john'), $second, '2026-09-02T10:00:00+02:00', null);
        $this->em->clear();

        $queries = $this->queriesDuring(function (): array {
            $names = [];
            foreach ($this->connections()->findExchangedExpiredForAdministration(null, 10) as $connection) {
                $names[] = $connection->client()->getName().'/'.$connection->account()->getUsername();
            }

            return $names;
        });

        self::assertSame(1, $queries['count'], 'Une requête pour la lecture, aucune de plus pour le client ni pour le compte.');
        // Activité décroissante : la connexion de john (2 septembre) vient d'abord,
        // chacune avec SON client et SON compte.
        self::assertSame(['Client John/john', 'Client Jane/jane'], $queries['result']);
    }

    /**
     * Même garde sur la page reprise : la branche à curseur ajoute une
     * condition au SQL natif, et c'est celle que la console parcourt dès la
     * deuxième page (#652). Le curseur vient de la dernière connexion d'une
     * première page de taille 1, l'identity map est vidée, puis la page
     * suivante coûte une requête et porte son client et son compte.
     */
    public function testExchangedExpiredResumedPageHydratesClientAndAccountWithoutFurtherQueries(): void
    {
        $first = $this->registerClient('https://client.example.test/metadata.json', 'Client Jane');
        $second = $this->registerClient('https://other-client.example.test/metadata.json', 'Client John');
        $this->expiredExchanged($this->persistAccount('jane'), $first, '2026-09-01T10:00:00+02:00', null);
        $this->expiredExchanged($this->persistAccount('john'), $second, '2026-09-02T10:00:00+02:00', null);
        $this->em->clear();

        $firstPage = $this->connections()->findExchangedExpiredForAdministration(null, 1);
        self::assertCount(1, $firstPage);
        $cursor = ExchangedExpiredCursor::after($firstPage[0]);
        $this->em->clear();

        $queries = $this->queriesDuring(function () use ($cursor): array {
            $names = [];
            foreach ($this->connections()->findExchangedExpiredForAdministration($cursor, 1) as $connection) {
                $names[] = $connection->client()->getName().'/'.$connection->account()->getUsername();
            }

            return $names;
        });

        self::assertSame(1, $queries['count'], 'Une requête pour la page reprise, aucune de plus pour le client ni pour le compte.');
        self::assertSame(['Client Jane/jane'], $queries['result']);
    }

    /**
     * Exécute `$action` et compte les requêtes que la connexion DBAL du
     * conteneur de test a envoyées pendant ce temps : le service
     * `doctrine.debug_data_holder` de DoctrineBundle (le middleware de
     * profilage) les consigne, `DebugStack` n'existant plus en DBAL 4. Le
     * relevé est remis à zéro avant l'appel ; un relevé vide après un appel
     * qui a lu la base fait échouer le test, plutôt que de laisser un compte
     * à zéro passer pour une preuve.
     *
     * @template T
     *
     * @param callable(): T $action
     *
     * @return array{count: int, result: T}
     */
    private function queriesDuring(callable $action): array
    {
        // Service privé, mais le conteneur de test l'expose.
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $result = $action();

        $count = \count($holder->getData()['default'] ?? []);
        self::assertGreaterThan(0, $count, 'Le relevé des requêtes est vide : le profilage DBAL est inactif, le compteur ne prouverait rien.');

        return ['count' => $count, 'result' => $result];
    }

    /**
     * @param list<McpConnection> $connections
     *
     * @return list<string> les identifiants RFC 4122, dans l'ordre reçu
     */
    private function idsOf(array $connections): array
    {
        return array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $connections);
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        TestDatabase::assertSafeToWipe($connection);
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

    private function registerClient(string $clientId, string $name = 'Client de test'): McpClient
    {
        $client = RegisteredMcpClient::of($clientId, $name, ['https://client.example.test/callback', 'http://127.0.0.1/callback']);
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
