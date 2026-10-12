<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\Version;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * #468 : le cache en base est vidé une fois, au passage aux valeurs signées.
 *
 * Une ligne écrite par l'ancien code n'a pas de MAC et serait refusée à chaque
 * lecture, sans jamais être supprimée. Les compteurs des limiteurs expirent
 * d'eux-mêmes, mais le signal de redémarrage du worker, posé sans expiration
 * par `messenger:stop-workers`, serait refusé à chaque tour du worker, avec un
 * `warning` par tour, indéfiniment (relecture de #468).
 */
final class Version20261011140000Test extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261011140000';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testEveryUnsignedRowIsRemoved(): void
    {
        $this->insertUnsigned('restart-signal:'.StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY, serialize(microtime(true)), null);
        $this->insertUnsigned('limiter:login_local_127.0.0.1', serialize(['tokens' => 3]), 900);

        $this->runUp();

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cache_items'));
    }

    private function insertUnsigned(string $id, string $data, ?int $lifetime): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cache_items (item_id, item_data, item_lifetime, item_time) VALUES (?, ?, ?, ?)',
            [$id, $data, $lifetime, time()],
            [ParameterType::STRING, ParameterType::LARGE_OBJECT, ParameterType::INTEGER, ParameterType::INTEGER],
        );
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
}
