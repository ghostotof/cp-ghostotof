<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * #650 (seconde relecture, décision de l'auteur du 2026-10-28) : retrait de
 * `cpg_user.credentials_changed_at`. Depuis S1 la version des identifiants
 * (`credentials_version`) juge une session ; la date, écrite à chaque
 * remplacement de mot de passe, n'était plus lue par aucune règle.
 *
 * `up()` retire la colonne ; `down()` la recrée **nullable**, vide : la date du
 * dernier changement est perdue, rien d'autre n'en dépend. Rejouée dans une
 * transaction annulée.
 */
final class Version20261028200000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261028200000';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertFalse($this->connection->isTransactionActive(), 'Une transaction est déjà ouverte avant le test.');
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /** La base migrée n'a plus la colonne. */
    public function testTheMigratedSchemaHasNoColumn(): void
    {
        self::assertNull($this->columnDescription());
    }

    /** `down()` la recrée nullable, avec fuseau, sans valeur par défaut autre que nulle. */
    public function testDownRecreatesTheNullableTimestampColumn(): void
    {
        $this->runDown();

        $column = $this->columnDescription();
        self::assertNotNull($column);
        self::assertSame('timestamp with time zone', $column['data_type']);
        self::assertSame('YES', $column['is_nullable']);
        self::assertTrue(null === $column['column_default'] || str_starts_with($column['column_default'], 'NULL'), 'Une valeur par défaut non nulle.');
    }

    /** Un compte écrit pendant que la colonne n'existe pas revient, la colonne recréée vide : rien n'est inventé. */
    public function testDownLeavesExistingAccountsNull(): void
    {
        $id = $this->insertAccount();

        $this->runDown();

        self::assertNull($this->connection->fetchOne('SELECT credentials_changed_at FROM cpg_user WHERE id = :id', ['id' => $id], ['id' => UuidType::NAME]));
    }

    /** `up()` retire la colonne ; les comptes restent, et la version des identifiants avec eux. */
    public function testUpRemovesTheColumnAndKeepsTheAccountsAndTheirVersion(): void
    {
        $this->runDown();
        $id = $this->insertAccount();
        $this->connection->executeStatement('UPDATE cpg_user SET credentials_version = 3 WHERE id = :id', ['id' => $id], ['id' => UuidType::NAME]);
        self::assertNotNull($this->columnDescription());

        $this->runUp();

        self::assertNull($this->columnDescription());
        self::assertSame(3, $this->connection->fetchOne('SELECT credentials_version FROM cpg_user WHERE id = :id', ['id' => $id], ['id' => UuidType::NAME]));
    }

    /**
     * @return array{data_type: string, is_nullable: string, column_default: string|null}|null
     */
    private function columnDescription(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, is_nullable, column_default FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'cpg_user' AND column_name = 'credentials_changed_at'",
        );
        if (false === $row) {
            return null;
        }
        self::assertIsString($row['data_type']);
        self::assertIsString($row['is_nullable']);
        self::assertTrue(null === $row['column_default'] || \is_string($row['column_default']));

        return ['data_type' => $row['data_type'], 'is_nullable' => $row['is_nullable'], 'column_default' => $row['column_default']];
    }

    /** Un compte écrit en SQL, sans la colonne retirée. */
    private function insertAccount(): UuidV7
    {
        $id = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO cpg_user (id, username, roles, password, invitation_generation) VALUES (:id, :username, :roles, :password, 0)',
            ['id' => $id, 'username' => 'migration-s4', 'roles' => '[]', 'password' => 'hashed-password'],
            ['id' => UuidType::NAME],
        );

        return $id;
    }
}
