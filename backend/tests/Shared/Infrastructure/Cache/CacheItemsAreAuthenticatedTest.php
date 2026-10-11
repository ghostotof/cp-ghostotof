<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Cache;

use App\Shared\Infrastructure\Cache\AuthenticatedCacheMarshaller;
use App\Tests\Shared\Infrastructure\Messenger\Fixtures\Probe\UnserializationProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

/**
 * Par le conteneur et la vraie table : une ligne `cache_items` altérée
 * n'atteint pas `unserialize()` (issue #468).
 *
 * AuthenticatedCacheMarshallerTest prouve le marshaller seul ; ce test-ci
 * prouve qu'il est bien celui de chaque pool en base, et que l'adaptateur
 * traite un refus comme une absence de cache plutôt que comme une panne.
 */
final class CacheItemsAreAuthenticatedTest extends KernelTestCase
{
    private const string KEY = 'issue-468-probe';

    protected function setUp(): void
    {
        self::bootKernel();
        UnserializationProbe::$unserializations = 0;
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement('DELETE FROM cache_items WHERE item_id LIKE :id', ['id' => '%'.self::KEY]);
        parent::tearDown();
    }

    /**
     * Les pools que DefaultMarshaller relisait : `cache.app` (limiteurs de
     * débit), le signal de redémarrage que lit le worker et le pool du client
     * HTTP. Le cache de résultats Doctrine (`doctrine.result_cache_pool`)
     * n'existe qu'en `when@prod`, absent du conteneur de test : il prend
     * `cache.app` pour adaptateur, donc le même marshaller.
     *
     * @return iterable<string, array{string}>
     */
    public static function databasePools(): iterable
    {
        yield 'cache.app' => ['cache.app'];
        yield 'signal de redémarrage du worker' => ['cache.messenger.restart_workers_signal'];
        yield 'pool du client HTTP' => ['cache.http_client.pool'];
    }

    #[DataProvider('databasePools')]
    public function testEveryDatabasePoolUsesTheAuthenticatedMarshaller(string $serviceId): void
    {
        $marshaller = new ReflectionProperty(DoctrineDbalAdapter::class, 'marshaller')->getValue($this->adapter($serviceId));

        self::assertInstanceOf(AuthenticatedCacheMarshaller::class, $marshaller, $serviceId.' relit cache_items sans authentifier les valeurs.');
    }

    /**
     * Une valeur légitime, changée en base derrière son MAC.
     */
    public function testATamperedRowNeverReachesUnserialize(): void
    {
        $pool = $this->adapter('cache.app');
        $pool->save($pool->getItem(self::KEY)->set(new UnserializationProbe()));
        $stored = $this->storedValue();
        self::assertStringContainsString('gadget', $stored);

        $this->overwriteStoredValue(str_replace('gadget', 'forged', $stored));

        self::assertFalse($pool->getItem(self::KEY)->isHit());
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Ce qu'écrirait un attaquant : un objet sérialisé nu, sans MAC.
     */
    public function testARowWrittenWithoutMacNeverReachesUnserialize(): void
    {
        $pool = $this->adapter('cache.app');
        $pool->save($pool->getItem(self::KEY)->set('valeur légitime'));

        $this->overwriteStoredValue(serialize(new UnserializationProbe()));

        self::assertFalse($pool->getItem(self::KEY)->isHit());
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    public function testAnUntamperedRowIsStillRead(): void
    {
        $pool = $this->adapter('cache.app');
        $pool->save($pool->getItem(self::KEY)->set(['tokens' => 4]));

        $item = $pool->getItem(self::KEY);

        self::assertTrue($item->isHit());
        self::assertSame(['tokens' => 4], $item->get());
    }

    private function adapter(string $serviceId): DoctrineDbalAdapter
    {
        $pool = self::getContainer()->get($serviceId);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        // En debug, chaque pool est enveloppé pour le profiler : on regarde
        // l'adaptateur réel, pas l'enveloppe.
        while ($pool instanceof TraceableAdapter) {
            $pool = $pool->getPool();
        }
        self::assertInstanceOf(DoctrineDbalAdapter::class, $pool, $serviceId);

        return $pool;
    }

    private function storedValue(): string
    {
        $data = $this->connection()->fetchOne('SELECT item_data FROM cache_items WHERE item_id LIKE :id', ['id' => '%'.self::KEY]);
        $data = \is_resource($data) ? stream_get_contents($data) : $data;
        self::assertIsString($data, 'La valeur sauvegardée doit être en base.');

        return $data;
    }

    private function overwriteStoredValue(string $data): void
    {
        $updated = $this->connection()->executeStatement(
            'UPDATE cache_items SET item_data = :data WHERE item_id LIKE :id',
            ['data' => $data, 'id' => '%'.self::KEY],
            ['data' => ParameterType::LARGE_OBJECT],
        );
        self::assertSame(1, $updated);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
