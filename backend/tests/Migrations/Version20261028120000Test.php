<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #650 (tâche 1) : la base admet les trois causes de révocation que la
 * personne, le changement de mot de passe et la perte du palier de confiance
 * écrivent — `person`, `password-changed`, `trusted-tier-withdrawn` — avant que
 * le code ne les écrive (expand/contract, #175).
 *
 * Rejouée dans une transaction annulée, sur un compte et un client que le test
 * écrit. Les causes sont écrites ici en clair, jamais lues sur l'énumération :
 * la migration est figée, et ce test dit ce qu'elle admet, quoi qu'ajoute
 * l'énumération plus tard (`McpConnectionRepositoryTest` relit, lui, chaque cas
 * de l'énumération contre la base migrée).
 */
final class Version20261028120000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261028120000';

    private const string CHECK_VIOLATION = '23514';

    private const string CONSTRAINT = 'chk_mcp_connection_revocation_cause_value';

    /** Les causes d'avant la migration : celles de #649. */
    private const array FORMER_CAUSES = ['client', 'refresh-token-reuse', 'code-replay', 'superseded', 'expired'];

    /** Les trois causes que la migration ajoute. */
    private const array NEW_CAUSES = ['person', 'password-changed', 'trusted-tier-withdrawn'];

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

        $account = new CpgUser('migration-650-t1', 'hashed-password');
        $entityManager->persist($account);
        $entityManager->flush();
        $client = RegisteredMcpClient::of('https://client.example.test/migration-650-t1', 'Client de test', ['https://client.example.test/callback']);
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

    public function testUpAdmitsTheThreeNewCauses(): void
    {
        // Revenue à l'état d'avant, la base refuse les trois valeurs : c'est la mesure de l'expand/contract.
        $this->runDown();
        foreach (self::NEW_CAUSES as $cause) {
            $this->assertRefused($cause);
        }

        $this->runUp();

        foreach ([...self::FORMER_CAUSES, ...self::NEW_CAUSES] as $cause) {
            self::assertSame(1, $this->insertRevokedWith($cause), \sprintf('La cause « %s » aurait dû être admise après up().', $cause));
        }
        // La liste reste fermée : une valeur hors des huit est toujours refusée.
        $this->assertRefused('unknown-cause');
    }

    public function testDownRestoresTheFiveCauses(): void
    {
        $this->runDown();

        foreach (self::FORMER_CAUSES as $cause) {
            self::assertSame(1, $this->insertRevokedWith($cause), \sprintf('La cause « %s » aurait dû rester admise après down().', $cause));
        }
        foreach ([...self::NEW_CAUSES, 'unknown-cause'] as $cause) {
            $this->assertRefused($cause);
        }
    }

    /**
     * Un retour arrière qui réécrirait en silence les lignes `person` en
     * `client` effacerait ce que la personne a décidé : `down()` échoue sur le
     * `ADD CONSTRAINT` et la base reste comme elle était, à qui de décider
     * quoi faire de ces lignes.
     */
    public function testDownRefusesWhileANewCauseIsStored(): void
    {
        self::assertSame(1, $this->insertRevokedWith('person'));

        // Un point de sauvegarde : l'échec annule la transaction de PostgreSQL entière sans lui.
        $this->connection->createSavepoint('before_down');
        try {
            $this->runDown();
            self::fail('down() aurait dû échouer tant qu\'une ligne porte une cause ajoutée.');
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString(self::CONSTRAINT, $exception->getMessage());
        }
        $this->connection->rollbackSavepoint('before_down');

        // La base est inchangée : la ligne y est, la contrainte à huit valeurs aussi (elle admet encore les trois, refuse le reste).
        self::assertSame(1, $this->countRevokedWith('person'));
        self::assertSame(1, $this->insertRevokedWith('trusted-tier-withdrawn'));
        $this->assertRefused('unknown-cause');
    }

    /** @return int le nombre de lignes écrites */
    private function insertRevokedWith(string $cause): int
    {
        return (int) $this->connection->executeStatement(
            'INSERT INTO mcp_connection (id, account_id, mcp_client_id, scope, granted_at, revoked_at, revocation_cause)'
            ." VALUES (:id, :account, :client, 'nominative:read', now(), now(), :cause)",
            ['id' => Uuid::v7(), 'account' => $this->accountId, 'client' => $this->clientId, 'cause' => $cause],
            ['id' => UuidType::NAME, 'account' => UuidType::NAME, 'client' => UuidType::NAME],
        );
    }

    private function countRevokedWith(string $cause): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM mcp_connection WHERE account_id = :account AND revocation_cause = :cause',
            ['account' => $this->accountId, 'cause' => $cause],
            ['account' => UuidType::NAME],
        );
        self::assertTrue(\is_int($count) || \is_string($count), 'Un compte de lignes est attendu.');

        return (int) $count;
    }

    /** La base refuse la cause, par la contrainte de valeur et en 23514 ; la transaction du test survit. */
    private function assertRefused(string $cause): void
    {
        $this->connection->createSavepoint('refused_cause');
        try {
            $this->insertRevokedWith($cause);
            self::fail(\sprintf('La cause « %s » aurait dû être refusée.', $cause));
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString(self::CONSTRAINT, $exception->getMessage());
        } finally {
            $this->connection->rollbackSavepoint('refused_cause');
        }
    }
}
