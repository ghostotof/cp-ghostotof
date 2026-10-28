<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * #730 : `mcp_connection.code_exchanged_at`, l'instant du premier échange du
 * code d'un octroi — nul tant que le code n'a jamais été échangé, ce qui
 * soumet la connexion à l'échéance des non échangées. La migration ajoute la
 * colonne **et rattrape les lignes existantes** : sans le rattrapage, toute
 * connexion déjà échangée serait lue comme jamais échangée, donc expirée une
 * heure après son octroi.
 *
 * Le rattrapage ne sait pas quand le code a été échangé, seulement qu'il l'a
 * été : une connexion qui porte un dernier usage, un jeton d'accès ou un jeton
 * de rafraîchissement l'a été (le code n'a pas d'autre issue qui émette un
 * jeton), et reçoit son octroi comme instant approché — seul « nul ou non » est
 * lu. Une connexion sans rien de cela reste nulle.
 *
 * Rejouée dans une transaction annulée, sur un compte et un client que le test
 * écrit : `down()` retire la colonne, les lignes s'écrivent sans elle, `up()`
 * la rejoue sur elles.
 */
final class Version20261028140000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261028140000';

    private Connection $connection;

    private Uuid $accountId;

    private Uuid $clientId;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $entityManager->getConnection();
        self::assertFalse($this->connection->isTransactionActive(), 'Une transaction est déjà ouverte avant le test.');
        $this->connection->beginTransaction();

        $account = new CpgUser('migration-730', 'hashed-password');
        $entityManager->persist($account);
        $entityManager->flush();
        $client = RegisteredMcpClient::of('https://client.example.test/migration-730', 'Client de test', ['https://client.example.test/callback']);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($client);
        $this->accountId = $account->getId();
        $this->clientId = $client->getId();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /** La colonne est un instant avec fuseau, nullable, sans valeur par défaut autre que nulle (PostgreSQL rend `DEFAULT NULL` en `NULL::<type>`). */
    public function testUpAddsTheNullableTimestampColumn(): void
    {
        $this->runDown();
        self::assertNull($this->columnDescription());

        $this->runUp();

        $column = $this->columnDescription();
        self::assertNotNull($column);
        self::assertSame('timestamp with time zone', $column['data_type']);
        self::assertSame('YES', $column['is_nullable']);
        self::assertTrue(null === $column['column_default'] || str_starts_with($column['column_default'], 'NULL'), 'Une valeur par défaut non nulle.');
    }

    /**
     * Le rattrapage : chaque connexion qui a échangé son code — un dernier
     * usage, un jeton d'accès, un jeton de rafraîchissement — reçoit son
     * octroi ; celle qui n'a rien de cela reste nulle (sans rattrapage, la
     * première serait expirée une heure après son octroi).
     */
    public function testUpCatchesUpTheConnectionsThatExchangedTheirCode(): void
    {
        $this->runDown();
        $used = $this->insertConnection("last_used_at = granted_at + INTERVAL '1 day'");
        $withAccessToken = $this->insertConnection();
        $this->insertAccessToken($withAccessToken);
        $withRefreshToken = $this->insertConnection();
        $this->insertRefreshToken($withRefreshToken);
        $neverExchanged = $this->insertConnection();

        $this->runUp();

        foreach ([$used, $withAccessToken, $withRefreshToken] as $exchanged) {
            self::assertSame($this->grantedAtOf($exchanged), $this->codeExchangedAtOf($exchanged), 'Une connexion qui a échangé son code n\'est pas rattrapée.');
            self::assertNotNull($this->codeExchangedAtOf($exchanged));
        }
        self::assertNull($this->codeExchangedAtOf($neverExchanged), 'Une connexion sans usage ni jeton a été rattrapée.');
    }

    /** `down()` retire la colonne ; les lignes restent. */
    public function testDownRemovesTheColumn(): void
    {
        $id = $this->insertConnection("last_used_at = granted_at + INTERVAL '1 day'");
        self::assertNotNull($this->columnDescription());

        $this->runDown();

        self::assertNull($this->columnDescription());
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM mcp_connection WHERE id = :id', ['id' => $id], ['id' => UuidType::NAME]));
    }

    /**
     * @return array{data_type: string, is_nullable: string, column_default: string|null}|null
     */
    private function columnDescription(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, is_nullable, column_default FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'mcp_connection' AND column_name = 'code_exchanged_at'",
        );
        if (false === $row) {
            return null;
        }
        self::assertIsString($row['data_type']);
        self::assertIsString($row['is_nullable']);
        self::assertTrue(null === $row['column_default'] || \is_string($row['column_default']));

        return ['data_type' => $row['data_type'], 'is_nullable' => $row['is_nullable'], 'column_default' => $row['column_default']];
    }

    /** Une connexion active écrite en SQL, sans la colonne ; `$extra` ajoute une affectation du `UPDATE` qui suit. */
    private function insertConnection(string $extra = ''): UuidV7
    {
        $id = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO mcp_connection (id, account_id, mcp_client_id, scope, granted_at)'
            ." VALUES (:id, :account, :client, 'nominative:read', now() - INTERVAL '5 days')",
            ['id' => $id, 'account' => $this->accountId, 'client' => $this->clientId],
            ['id' => UuidType::NAME, 'account' => UuidType::NAME, 'client' => UuidType::NAME],
        );
        if ('' !== $extra) {
            $this->connection->executeStatement('UPDATE mcp_connection SET '.$extra.' WHERE id = :id', ['id' => $id], ['id' => UuidType::NAME]);
        }

        return $id;
    }

    private function insertAccessToken(Uuid $connectionId): void
    {
        $id = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO mcp_access_token (id, token_hash, audience, expires_at, connection_id)'
            ." VALUES (:id, :hash, 'https://example.test/api/mcp', now() + INTERVAL '1 hour', :connection)",
            ['id' => $id, 'hash' => hash('sha256', $id->toRfc4122().'-access'), 'connection' => $connectionId],
            ['id' => UuidType::NAME, 'connection' => UuidType::NAME],
        );
    }

    private function insertRefreshToken(Uuid $connectionId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO mcp_refresh_token (id, token_hash, expires_at, connection_id)'
            ." VALUES (:id, :hash, now() + INTERVAL '30 days', :connection)",
            ['id' => Uuid::v7(), 'hash' => hash('sha256', $connectionId->toRfc4122().'-refresh'), 'connection' => $connectionId],
            ['id' => UuidType::NAME, 'connection' => UuidType::NAME],
        );
    }

    private function grantedAtOf(Uuid $id): ?string
    {
        return $this->instantOf('granted_at', $id);
    }

    private function codeExchangedAtOf(Uuid $id): ?string
    {
        return $this->instantOf('code_exchanged_at', $id);
    }

    /** L'instant d'une colonne, en époque Unix (comparaison d'instants, jamais de chaînes affichées). */
    private function instantOf(string $column, Uuid $id): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT extract(epoch FROM '.$column.')::text FROM mcp_connection WHERE id = :id',
            ['id' => $id],
            ['id' => UuidType::NAME],
        );

        return \is_string($value) ? $value : null;
    }
}
