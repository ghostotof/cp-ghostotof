<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #652 (S4) : les trois index partiels de la liste d'administration des
 * connexions MCP. `up()` les crée, `down()` les retire sans toucher une ligne ;
 * rejouée dans une transaction annulée. Que chacun serve sa lecture est épinglé
 * par `McpConnectionAdministrationIndexesTest`.
 */
final class Version20261120120000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261120120000';

    /** @var array<string, string> le nom de chaque index, et un fragment de son prédicat */
    private const array INDEXES = [
        'idx_mcp_connection_admin_terminated' => "<> 'expired'::text",
        'idx_mcp_connection_admin_abandoned' => '(code_exchanged_at IS NULL)',
        'idx_mcp_connection_admin_exchanged_expired' => "= 'expired'::text",
    ];

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

    /** La base migrée porte les trois index, partiels. */
    public function testTheMigratedSchemaHasThePartialIndexes(): void
    {
        foreach (self::INDEXES as $name => $predicate) {
            $definition = $this->definitionOf($name);
            self::assertNotNull($definition, \sprintf('L\'index « %s » manque.', $name));
            self::assertStringContainsString(' WHERE ', $definition);
            self::assertStringContainsString($predicate, $definition);
        }
    }

    /** `down()` les retire tous, `up()` les recrée à l'identique. */
    public function testDownRemovesThemAndUpRestoresThemIdentically(): void
    {
        $before = array_map($this->definitionOf(...), array_keys(self::INDEXES));

        $this->runDown();
        foreach (array_keys(self::INDEXES) as $name) {
            self::assertNull($this->definitionOf($name), \sprintf('L\'index « %s » survit à down().', $name));
        }

        $this->runUp();
        self::assertSame($before, array_map($this->definitionOf(...), array_keys(self::INDEXES)));
    }

    private function definitionOf(string $index): ?string
    {
        $definition = $this->connection->fetchOne('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = :name', ['name' => $index]);

        return \is_string($definition) ? $definition : null;
    }
}
