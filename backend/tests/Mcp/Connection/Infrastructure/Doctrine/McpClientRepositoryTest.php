<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\ProbesLocks;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Test d'intégration Doctrine (base `_test`) du mode d'enregistrement d'un
 * client MCP (#651) : la colonne `registration`, sa contrainte, le dépôt d'un
 * client déclaré par son document (`saveDiscovered`, un INSERT … ON CONFLICT
 * qui ne réécrit jamais une ligne pré-enregistrée) et la question
 * `isDiscovered` qui choisit la voie du quota de récupération.
 */
final class McpClientRepositoryTest extends KernelTestCase
{
    use ProbesLocks;

    private const string PREREGISTERED_ID = 'https://client.example.test/metadata.json';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        $this->purge();
        parent::tearDown();
    }

    public function testOnlyACimdRowIsDiscovered(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Pré-enregistré', ['https://client.example.test/cb']));
        self::assertInstanceOf(McpClient::class, $this->repository()->saveDiscovered($this->discovered()));

        self::assertTrue($this->repository()->isDiscovered(McpClientId::fromString(ClaudeCodeMetadata::CLIENT_ID)));
        self::assertFalse($this->repository()->isDiscovered(McpClientId::fromString(self::PREREGISTERED_ID)));
        self::assertFalse($this->repository()->isDiscovered(McpClientId::fromString('https://absent.example.test/metadata.json')));
    }

    public function testADiscoveredClientIsInsertedOnce(): void
    {
        $saved = $this->repository()->saveDiscovered($this->discovered());

        self::assertInstanceOf(McpClient::class, $saved);
        self::assertSame(ClientRegistration::Cimd, $saved->registration());
        self::assertSame('Claude Code', $saved->getName());
        self::assertSame(1, $this->countRows());
    }

    public function testASecondSaveRefreshesNameAndRedirectsAndKeepsIdAndDate(): void
    {
        $first = $this->repository()->saveDiscovered($this->discovered(registeredAt: new DateTimeImmutable('2026-11-01T10:00:00+01:00')));
        self::assertInstanceOf(McpClient::class, $first);
        $id = $first->getId()->toRfc4122();
        $registeredAt = $first->registeredAt();

        $second = $this->repository()->saveDiscovered($this->discovered(
            json: '{"client_id":"'.ClaudeCodeMetadata::CLIENT_ID.'","client_name":"Claude Code 2","redirect_uris":["http://localhost/other"],"token_endpoint_auth_method":"none"}',
            registeredAt: new DateTimeImmutable('2026-11-13T10:00:00+01:00'),
        ));

        self::assertInstanceOf(McpClient::class, $second);
        self::assertSame($id, $second->getId()->toRfc4122(), 'Le client garde son identifiant : les connexions y sont attachées.');
        self::assertSame('Claude Code 2', $second->getName());
        self::assertSame(['http://localhost/other'], $second->redirectUris());
        self::assertSame($registeredAt->getTimestamp(), $second->registeredAt()->getTimestamp(), 'La date d\'enregistrement est celle du premier dépôt.');
        self::assertSame(1, $this->countRows());
    }

    /** Un client pré-enregistré (le sien est jugé par une personne) n'est jamais réécrit par un document. */
    public function testAPreregisteredRowIsNeverOverwritten(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Nom choisi par une personne', ['https://client.example.test/cb']));
        $this->em->clear();

        self::assertNull($this->repository()->saveDiscovered($this->discovered()));

        $row = $this->em->getConnection()->fetchAssociative('SELECT name, redirect_uris, registration FROM mcp_client');
        self::assertIsArray($row);
        self::assertSame('Nom choisi par une personne', $row['name']);
        self::assertSame('["https:\/\/client.example.test\/cb"]', $row['redirect_uris']);
        self::assertSame('preregistered', $row['registration']);
    }

    public function testTheColumnRefusesAnUnknownRegistration(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']));

        $this->expectException(DbalException::class);
        $this->em->getConnection()->executeStatement("UPDATE mcp_client SET registration = 'x'");
    }

    /** Une ligne écrite sans la colonne (une version de l'application d'avant) est pré-enregistrée. */
    public function testARowWrittenWithoutTheColumnIsPreregistered(): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO mcp_client (id, client_id, name, redirect_uris, registered_at) VALUES ('0195f3a0-0000-7000-8000-000000000001', :clientId, 'Ancien', '[]', NOW())",
            ['clientId' => self::PREREGISTERED_ID],
        );

        self::assertSame('preregistered', $this->em->getConnection()->fetchOne('SELECT registration FROM mcp_client'));
        self::assertFalse($this->repository()->isDiscovered(McpClientId::fromString(self::PREREGISTERED_ID)));
    }

    /**
     * La liste de l'administration (#652) : les deux modes d'enregistrement,
     * le plus récent d'abord ; à date égale, l'identifiant (UUID v7, donc
     * ordonné dans le temps) le plus grand d'abord, pour un ordre stable.
     */
    public function testAllListsBothRegistrationsMostRecentFirst(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Ancien', ['https://client.example.test/cb'], new DateTimeImmutable('2026-11-01T10:00:00+01:00')));
        $discovered = $this->repository()->saveDiscovered($this->discovered(registeredAt: new DateTimeImmutable('2026-11-13T10:00:00+01:00')));
        self::assertInstanceOf(McpClient::class, $discovered);
        $this->em->clear();

        $all = $this->repository()->all();

        self::assertSame(
            [ClaudeCodeMetadata::CLIENT_ID, self::PREREGISTERED_ID],
            array_map(static fn (McpClient $client): string => $client->getClientId(), $all),
        );
        self::assertSame([ClientRegistration::Cimd, ClientRegistration::Preregistered], array_map(static fn (McpClient $client): ClientRegistration => $client->registration(), $all));
    }

    /** À date d'enregistrement égale, l'identifiant décroissant tranche : l'ordre ne dépend pas du plan de la base. */
    public function testAllBreaksATieOnTheRegistrationDateByDescendingId(): void
    {
        $at = new DateTimeImmutable('2026-11-13T10:00:00+01:00');
        $first = RegisteredMcpClient::of('https://a.example.test/metadata.json', 'A', ['https://a.example.test/cb'], $at);
        $second = RegisteredMcpClient::of('https://b.example.test/metadata.json', 'B', ['https://b.example.test/cb'], $at);
        $this->repository()->add($first);
        $this->repository()->add($second);
        $this->em->clear();

        $expected = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        rsort($expected);

        self::assertSame($expected, array_map(static fn (McpClient $client): string => $client->getId()->toRfc4122(), $this->repository()->all()));
    }

    public function testAllIsEmptyWithoutAnyClient(): void
    {
        self::assertSame([], $this->repository()->all());
    }

    /**
     * La lecture simple d'un client (#652, le détail du backoffice) : par sa
     * clé UUID, sans verrou et sans transaction — celle-ci n'est pas ouverte
     * ici, et la lecture verrouillée l'aurait refusée. Le bon client est rendu
     * parmi plusieurs, relu depuis la base (l'unité de travail est vidée).
     */
    public function testFindOneByIdReturnsTheClientWithoutATransaction(): void
    {
        $wanted = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Voulu', ['https://client.example.test/cb']);
        $this->repository()->add($wanted);
        $this->repository()->add(RegisteredMcpClient::of('https://other.example.test/metadata.json', 'Autre', ['https://other.example.test/cb']));
        $this->em->clear();

        self::assertFalse($this->em->getConnection()->isTransactionActive(), 'La lecture simple ne doit pas exiger de transaction.');
        $found = $this->repository()->findOneById($wanted->getId());

        self::assertInstanceOf(McpClient::class, $found);
        self::assertTrue($wanted->getId()->equals($found->getId()));
        self::assertSame('Voulu', $found->getName());
    }

    /** Un identifiant que la base ne porte pas : `null`, jamais une exception. */
    public function testFindOneByIdReturnsNullForAnUnknownId(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']));
        $this->em->clear();

        self::assertNull($this->repository()->findOneById(Uuid::v7()));
    }

    /** Une ligne supprimée entre la lecture de l'administrateur et son verrou : rien, et l'instance périmée est détachée. */
    public function testFindOneByIdForUpdateReturnsNullForAVanishedRow(): void
    {
        $client = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']);
        $this->repository()->add($client);
        $id = $client->getId();
        $this->em->getConnection()->executeStatement('DELETE FROM mcp_client WHERE id = :id', ['id' => $id->toRfc4122()]);
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $found = $this->repository()->findOneByIdForUpdate($id);

        self::assertNull($found);
        self::assertFalse($this->em->contains($client), "L'instance périmée est restée gérée.");
    }

    /** La ligne existe : la même instance, rafraîchie, et verrouillée en écriture (`FOR UPDATE`). */
    public function testFindOneByIdForUpdateRefreshesAndLocksTheRow(): void
    {
        $client = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Avant', ['https://client.example.test/cb']);
        $this->repository()->add($client);
        $this->em->getConnection()->executeStatement('UPDATE mcp_client SET name = :name WHERE id = :id', ['name' => 'Après', 'id' => $client->getId()->toRfc4122()]);
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);

        try {
            $connection->beginTransaction();
            $found = $this->repository()->findOneByIdForUpdate($client->getId());

            self::assertSame($client, $found);
            self::assertSame('Après', $client->getName(), "L'instance n'a pas été rafraîchie.");
            self::assertFalse($this->probeCanLockTheRow($probe, 'mcp_client', $client->getId()), "La ligne n'est pas verrouillée.");
        } finally {
            $probe->close();
        }
    }

    /**
     * Le verrou partagé du consentement (#652) : il ne bloque pas un autre
     * consentement (`FOR SHARE` est compatible avec lui-même), mais interdit
     * la suppression ou la révision du client (`FOR UPDATE`) tant que la
     * transaction court. `RowShareLock` sur la table, vu depuis une autre
     * session.
     */
    public function testFindOneByClientIdForShareTakesASharedLock(): void
    {
        $client = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']);
        $this->repository()->add($client);
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);

        try {
            $connection->beginTransaction();
            $found = $this->repository()->findOneByClientIdForShare(self::PREREGISTERED_ID);

            self::assertSame($client, $found);
            self::assertTrue($this->probeSeesARowShareLockOnTheClients($probe), 'Aucun RowShareLock sur mcp_client.');
            self::assertFalse($this->probeCanLockTheRow($probe, 'mcp_client', $client->getId()), 'Une suppression concurrente passerait (FOR UPDATE obtenu).');
            self::assertTrue($this->probeCanShareTheRow($probe, $client->getId()), 'Le verrou est exclusif : un autre consentement attendrait.');
        } finally {
            $probe->close();
        }
    }

    /**
     * `FOR SHARE` et non `FOR KEY SHARE` (#652) : `FOR KEY SHARE`, le plus faible
     * des verrous de ligne, laisserait passer une révision du client (`UPDATE`
     * qui ne touche pas la clé, donc `FOR NO KEY UPDATE`). `FOR SHARE` la bloque.
     * `NOWAIT` : une sonde qui attendrait bloquerait le test ; le refus attendu
     * est l'état SQL `55P03` (lock_not_available).
     *
     * `FOR UPDATE SKIP LOCKED` (test précédent) ne distingue pas les deux : il
     * échoue contre les deux verrous.
     */
    public function testFindOneByClientIdForShareBlocksARevisionOfTheRow(): void
    {
        $client = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']);
        $this->repository()->add($client);
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);

        try {
            $connection->beginTransaction();
            $this->repository()->findOneByClientIdForShare(self::PREREGISTERED_ID);

            try {
                $probe->fetchOne('SELECT 1 FROM mcp_client WHERE id = :id FOR NO KEY UPDATE NOWAIT', ['id' => $client->getId()->toRfc4122()]);
                self::fail('Une révision du client (FOR NO KEY UPDATE) passerait : le verrou est plus faible que FOR SHARE.');
            } catch (DriverException $refusal) {
                self::assertSame('55P03', $refusal->getSQLState());
            }
        } finally {
            $probe->close();
        }
    }

    /**
     * Sans transaction, Doctrine refuse de poser le verrou : `setLockMode()`
     * lève avant toute requête ({@see \App\Shared\Infrastructure\Doctrine\RereadUnderLock::row()} le propage,
     * sans le rattraper). Un appelant hors transaction est un bogue
     * de câblage, qui doit échouer bruyamment et non lire sans verrou.
     */
    public function testFindOneByIdForUpdateRefusesToRunOutsideATransaction(): void
    {
        $client = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']);
        $this->repository()->add($client);
        self::assertFalse($this->em->getConnection()->isTransactionActive());

        $this->expectException(TransactionRequiredException::class);

        $this->repository()->findOneByIdForUpdate($client->getId());
    }

    public function testFindOneByClientIdForShareRefusesToRunOutsideATransaction(): void
    {
        $client = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']);
        $this->repository()->add($client);
        self::assertFalse($this->em->getConnection()->isTransactionActive());

        $this->expectException(TransactionRequiredException::class);

        $this->repository()->findOneByClientIdForShare(self::PREREGISTERED_ID);
    }

    public function testFindOneByClientIdForShareReturnsNullForAnUnknownClient(): void
    {
        $this->em->getConnection()->beginTransaction();

        self::assertNull($this->repository()->findOneByClientIdForShare('https://absent.example.test/metadata.json'));
    }

    /**
     * Les clés étrangères portent `ON DELETE CASCADE` (migration, pas de
     * `removed_at`) : la suppression du client emporte ses connexions, et par
     * elles leurs codes, jetons d'accès et jetons de rafraîchissement.
     */
    public function testRemovingAClientCascadesToItsConnectionsAndTokens(): void
    {
        $account = new CpgUser('jane', 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();
        $client = RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']);
        $this->repository()->add($client);
        $connection = McpConnection::grant($account, $client, McpConnection::SCOPE_NOMINATIVE_READ, new DateTimeImmutable());
        self::getContainer()->get(McpConnectionRepositoryInterface::class)->add($connection);
        $this->em->persist(new McpAuthorizationCode($connection, hash('sha256', 'code'), new DateTimeImmutable('+1 minute')));
        $access = new McpAccessToken($connection, hash('sha256', 'access'), 'https://example.test/api/mcp', new DateTimeImmutable('+1 hour'));
        $this->em->persist($access);
        $this->em->persist(new McpRefreshToken($connection, hash('sha256', 'refresh'), $access, new DateTimeImmutable('+30 days')));
        $this->em->flush();
        foreach (['mcp_connection', 'mcp_authorization_code', 'mcp_access_token', 'mcp_refresh_token'] as $table) {
            self::assertSame(1, $this->countRows($table), $table.' : la ligne de départ manque.');
        }

        $this->repository()->remove($client);

        foreach (['mcp_client', 'mcp_connection', 'mcp_authorization_code', 'mcp_access_token', 'mcp_refresh_token'] as $table) {
            self::assertSame(0, $this->countRows($table), $table.' : une ligne a survécu à la suppression du client.');
        }
        self::assertSame(1, $this->countRows('cpg_user'), 'Le compte ne doit pas être emporté.');
    }

    private function discovered(?string $json = null, ?DateTimeImmutable $registeredAt = null): McpClient
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);

        return McpClient::discovered(
            ClientMetadataDocument::fromJson($clientId, $json ?? ClaudeCodeMetadata::json()),
            $registeredAt ?? new DateTimeImmutable('2026-11-13T10:00:00+01:00'),
        );
    }

    /** Un verrou de ligne pris par une autre session sur `mcp_client` : `RowShareLock` (`FOR SHARE` ou `FOR UPDATE`). */
    private function probeSeesARowShareLockOnTheClients(Connection $probe): bool
    {
        return 0 < $probe->fetchOne(
            "SELECT COUNT(*) FROM pg_locks WHERE relation = 'mcp_client'::regclass AND mode = 'RowShareLock' AND granted AND pid <> pg_backend_pid()",
        );
    }

    /** `FOR SHARE SKIP LOCKED` : obtenu tant que le verrou tenu est lui-même partagé. */
    private function probeCanShareTheRow(Connection $probe, Uuid $id): bool
    {
        return 1 === $probe->fetchOne('SELECT 1 FROM mcp_client WHERE id = :id FOR SHARE SKIP LOCKED', ['id' => $id->toRfc4122()]);
    }

    private function countRows(string $table = 'mcp_client'): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client', 'cpg_user'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $this->em->clear();
    }

    private function repository(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }
}
