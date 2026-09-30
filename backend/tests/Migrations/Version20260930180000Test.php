<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Régression #287 : les produits suivis d'avant #19 étaient restés en source
 * `manual`, avec une version saisie qui ne correspondait plus à ce qui tourne.
 *
 * La base de test est migrée avant la suite (CI : `doctrine:migrations:migrate`),
 * la migration y est donc déjà passée sur une table vide. Le test reconstruit
 * l'état d'une base d'avant #19, puis rejoue le SQL de `up()`, ce qui prouve au
 * passage que la migration est rejouable.
 */
final class Version20260930180000Test extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20260930180000';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('DELETE FROM watched_product');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM watched_product');
        parent::tearDown();
    }

    public function testTheFiveDeployedProductsLeaveTheManualSource(): void
    {
        $this->insertProduct('postgresql', 'manual', '18.4', 2);
        $this->insertProduct('nodejs', 'manual', '26.7.0', 3);
        $this->insertProduct('vue', 'manual', '3.5.42', 4);
        $this->insertProduct('nginx', 'manual', '1.30.4', 5);
        $this->insertProduct('rabbitmq', 'manual', '4.3.4', 6);

        $this->runUp();

        foreach (['postgresql', 'nodejs', 'vue', 'nginx', 'rabbitmq'] as $slug) {
            self::assertSame(
                ['version_source' => 'deployed', 'version' => null],
                $this->row($slug),
                sprintf('%s doit être relevé au build, plus saisi.', $slug),
            );
        }
    }

    /**
     * Un produit hors du relevé de build garde sa saisie manuelle : il n'y a
     * rien d'autre pour dire sa version.
     */
    public function testAManualProductOutsideTheBuildRecordIsLeftAlone(): void
    {
        $this->insertProduct('redis', 'manual', '8.2', 7);

        $this->runUp();

        self::assertSame(['version_source' => 'manual', 'version' => '8.2'], $this->row('redis'));
    }

    public function testRuntimeAndDeployedProductsAreLeftAlone(): void
    {
        $this->insertProduct('php', 'runtime_php', null, 0);
        $this->insertProduct('symfony', 'runtime_symfony', null, 1);
        $this->insertProduct('postgresql', 'deployed', null, 2);

        $this->runUp();

        self::assertSame(['version_source' => 'runtime_php', 'version' => null], $this->row('php'));
        self::assertSame(['version_source' => 'runtime_symfony', 'version' => null], $this->row('symfony'));
        self::assertSame(['version_source' => 'deployed', 'version' => null], $this->row('postgresql'));
    }

    /**
     * Le résultat doit être une ligne que l'entité accepte : une source
     * résolue à l'exécution sans version stockée.
     */
    public function testAMigratedRowHydratesAsADeployedProduct(): void
    {
        $this->insertProduct('postgresql', 'manual', '18.4', 2);

        $this->runUp();

        $products = self::getContainer()->get(WatchedProductRepositoryInterface::class)->findAllOrdered();
        self::assertCount(1, $products);
        self::assertSame(VersionSource::DEPLOYED, $products[0]->getVersionSource());
        self::assertNull($products[0]->getVersion());
    }

    public function testTheMigrationRefusesToGoDown(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->migration()->down(new Schema());
    }

    private function runUp(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function migration(): AbstractMigration
    {
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        return $factory->getMigrationRepository()->getMigration(new Version(self::MIGRATION))->getMigration();
    }

    private function insertProduct(string $slug, string $source, ?string $version, int $position): void
    {
        $this->connection->insert('watched_product', [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'label' => ucfirst($slug),
            'version_source' => $source,
            'version' => $version,
            'position' => $position,
        ]);
    }

    /**
     * @return array{version_source: mixed, version: mixed}
     */
    private function row(string $slug): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT version_source, version FROM watched_product WHERE slug = ?',
            [$slug],
        );
        self::assertIsArray($row);

        return ['version_source' => $row['version_source'], 'version' => $row['version']];
    }
}
