<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #652 (S4d) : l'index des expirées échangées par `revoked_at` est remplacé par
 * un index d'expression partiel sur l'activité. `up()` fait l'échange, `down()`
 * le défait sans toucher une ligne ; rejouée dans une transaction annulée. Que
 * l'index serve tri et reprise est épinglé par `McpConnectionAdministrationIndexesTest`.
 */
final class Version20261120130000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261120130000';

    private const string NEW_INDEX = 'idx_mcp_connection_admin_exchanged_expired_activity';

    private const string OLD_INDEX = 'idx_mcp_connection_admin_exchanged_expired';

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

    /** La base migrée porte l'index d'expression partiel, et plus l'ancien. */
    public function testTheMigratedSchemaHasTheExpressionIndexOnly(): void
    {
        $definition = $this->definitionOf(self::NEW_INDEX);

        self::assertNotNull($definition, 'L\'index d\'activité manque.');
        self::assertStringContainsString('COALESCE(last_used_at, granted_at)', $definition);
        self::assertStringContainsString(' WHERE ', $definition);
        self::assertStringContainsString("= 'expired'::text", $definition);
        self::assertNull($this->definitionOf(self::OLD_INDEX), 'L\'ancien index survit à up().');
    }

    /** `down()` rétablit l'ancien index, `up()` rétablit l'index d'activité à l'identique. */
    public function testDownRestoresTheOldIndexAndUpTheExpressionIndexIdentically(): void
    {
        $before = $this->definitionOf(self::NEW_INDEX);

        $this->runDown();
        self::assertNull($this->definitionOf(self::NEW_INDEX), 'L\'index d\'activité survit à down().');
        self::assertNotNull($this->definitionOf(self::OLD_INDEX), 'down() ne rétablit pas l\'ancien index.');

        $this->runUp();
        self::assertSame($before, $this->definitionOf(self::NEW_INDEX));
        self::assertNull($this->definitionOf(self::OLD_INDEX));
    }

    private function definitionOf(string $index): ?string
    {
        $definition = $this->connection->fetchOne('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = :name', ['name' => $index]);

        return \is_string($definition) ? $definition : null;
    }
}
