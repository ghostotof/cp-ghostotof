<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #649 (relecture du lot B, D5) : la base n'admet dans
 * `mcp_connection.revocation_cause` que les valeurs de {@see RevocationCause}.
 *
 * Rejouée dans une transaction annulée, sur un compte et un client que le test
 * écrit : `down()` retire la contrainte et la base accepte alors une cause
 * inconnue — ce que `up()` rejoué referme. Le refus sur la base migrée, cas par
 * cas de l'énumération, est épinglé par `McpConnectionRepositoryTest` ; ici,
 * que `down()` rend honnêtement l'état d'avant.
 */
final class Version20261027120000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261027120000';

    private const string CHECK_VIOLATION = '23514';

    private const string UNKNOWN_CAUSE_INSERT = 'INSERT INTO mcp_connection (id, account_id, mcp_client_id, scope, granted_at, revoked_at, revocation_cause)'
        ." VALUES (:id, :account, :client, 'nominative:read', now(), now(), 'unknown-cause')";

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

        $account = new CpgUser('migration-649-d5', 'hashed-password');
        $entityManager->persist($account);
        $entityManager->flush();
        $client = RegisteredMcpClient::of('https://client.example.test/migration-649-d5', 'Client de test', ['https://client.example.test/callback']);
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

    public function testDownAdmitsAnUnknownCauseAgainAndUpRefusesIt(): void
    {
        $this->runDown();

        $written = $this->insertAnUnknownCause();
        self::assertSame(1, $written, "Sans la contrainte, une cause inconnue aurait dû s'écrire.");

        // La ligne retirée, `up()` repose la contrainte, qui refuse la même écriture.
        $this->connection->executeStatement('DELETE FROM mcp_connection WHERE account_id = :account', ['account' => $this->accountId], ['account' => UuidType::NAME]);
        $this->runUp();

        try {
            $this->insertAnUnknownCause();
            self::fail('La contrainte reposée aurait dû refuser une cause inconnue.');
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString('chk_mcp_connection_revocation_cause_value', $exception->getMessage());
        }
    }

    /** @return int le nombre de lignes écrites */
    private function insertAnUnknownCause(): int
    {
        return (int) $this->connection->executeStatement(
            self::UNKNOWN_CAUSE_INSERT,
            ['id' => Uuid::v7(), 'account' => $this->accountId, 'client' => $this->clientId],
            ['id' => UuidType::NAME, 'account' => UuidType::NAME, 'client' => UuidType::NAME],
        );
    }
}
