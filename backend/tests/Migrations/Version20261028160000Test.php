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
 * #650 (relecture R1, F2) : `cpg_user.credentials_changed_at`, l'instant où le
 * mot de passe d'un compte a changé pour la dernière fois (le `PUT` du
 * backoffice, ou le lien d'invitation qui le pose ou le remplace). Le
 * consentement MCP refuse un cookie de session authentifié avant cet instant.
 *
 * Nulle pour tout compte existant, et c'est voulu : « jamais changé depuis la
 * colonne » ne refuse aucun cookie, exactement le comportement d'avant. La
 * migration n'invente aucun instant (`now()` aurait invalidé d'un coup chaque
 * session ouverte, une heure durant, sans raison de sécurité).
 *
 * Rejouée dans une transaction annulée : `down()` retire la colonne, `up()`
 * l'ajoute sur les comptes que le test a écrits.
 *
 * **La colonne n'existe plus dans la base migrée** : `Version20261028200000` la
 * retire (S4 de la seconde relecture, la version des identifiants l'a remplacée).
 * Le test commence donc, dans sa transaction annulée, par l'état que cette migration
 * défait — la colonne recréée comme son `down()` la recrée — avant de rejouer
 * `down()`/`up()` de celle-ci.
 */
final class Version20261028160000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261028160000';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertFalse($this->connection->isTransactionActive(), 'Une transaction est déjà ouverte avant le test.');
        $this->connection->beginTransaction();
        // L'état d'avant Version20261028200000, comme son down() le rétablit.
        $this->connection->executeStatement('ALTER TABLE cpg_user ADD credentials_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /** Un instant avec fuseau, nullable, sans valeur par défaut autre que nulle (PostgreSQL rend `DEFAULT NULL` en `NULL::<type>`). */
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

    /** Aucun rattrapage : un compte déjà là reste « jamais changé », ses sessions ouvertes ne sont pas touchées. */
    public function testUpLeavesExistingAccountsNull(): void
    {
        $this->runDown();
        $id = $this->insertAccount();

        $this->runUp();

        self::assertNull($this->connection->fetchOne('SELECT credentials_changed_at FROM cpg_user WHERE id = :id', ['id' => $id], ['id' => UuidType::NAME]));
    }

    /** `down()` retire la colonne ; les comptes restent. */
    public function testDownRemovesTheColumn(): void
    {
        $id = $this->insertAccount();
        self::assertNotNull($this->columnDescription());

        $this->runDown();

        self::assertNull($this->columnDescription());
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM cpg_user WHERE id = :id', ['id' => $id], ['id' => UuidType::NAME]));
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

    /** Un compte écrit en SQL, sans la colonne (celle-ci peut manquer au moment de l'écriture). */
    private function insertAccount(): UuidV7
    {
        $id = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO cpg_user (id, username, roles, password, invitation_generation) VALUES (:id, :username, :roles, :password, 0)',
            ['id' => $id, 'username' => 'migration-r1', 'roles' => '[]', 'password' => 'hashed-password'],
            ['id' => UuidType::NAME],
        );

        return $id;
    }
}
