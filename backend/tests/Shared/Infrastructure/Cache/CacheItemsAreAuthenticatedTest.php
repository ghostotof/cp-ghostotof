<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Cache;

use App\Shared\Infrastructure\Cache\AuthenticatedCacheMarshaller;
use App\Tests\Support\UnserializationProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpKernel\CacheClearer\Psr6CacheClearer;

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
     * Chaque pool en base du conteneur, énuméré plutôt que listé à la main : un
     * pool ajouté plus tard, ou un adaptateur construit sans
     * `cache.default_marshaller`, fait rougir ce test au lieu de lui échapper.
     * `cache.global_clearer` reçoit tous les pools que déclare
     * {@see \Symfony\Component\Cache\DependencyInjection\CachePoolPass}.
     * Le cache de résultats Doctrine (`doctrine.result_cache_pool`) n'existe
     * qu'en `when@prod`, absent du conteneur de test : il prend `cache.app` pour
     * adaptateur, donc le même marshaller.
     */
    public function testEveryDatabasePoolUsesTheAuthenticatedMarshaller(): void
    {
        $clearer = self::getContainer()->get('cache.global_clearer');
        /** @var array<string, CacheItemPoolInterface> $pools */
        $pools = new ReflectionProperty(Psr6CacheClearer::class, 'pools')->getValue($clearer);

        $databasePools = [];
        foreach ($pools as $name => $pool) {
            while ($pool instanceof TraceableAdapter) {
                $pool = $pool->getPool();
            }
            if (!$pool instanceof DoctrineDbalAdapter) {
                continue;
            }

            $databasePools[] = $name;
            $marshaller = new ReflectionProperty(DoctrineDbalAdapter::class, 'marshaller')->getValue($pool);
            self::assertInstanceOf(AuthenticatedCacheMarshaller::class, $marshaller, $name.' relit cache_items sans authentifier les valeurs.');
        }

        // Les lecteurs que nomme l'issue : l'API par les limiteurs, le worker
        // par le signal de redémarrage. Garde contre une énumération vide.
        self::assertContains('cache.app', $databasePools);
        self::assertContains('cache.rate_limiter', $databasePools);
        self::assertContains('cache.messenger.restart_workers_signal', $databasePools);
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
