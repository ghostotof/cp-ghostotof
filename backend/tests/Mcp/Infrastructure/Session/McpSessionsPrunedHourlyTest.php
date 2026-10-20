<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Session;

use App\Tests\Support\KubernetesManifests;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les sessions MCP expirées quittent `cache_items` dans l'heure (#644).
 *
 * L'adaptateur DBAL n'efface jamais une ligne expirée de lui-même : seul
 * `cache:pool:prune` le fait, et le CronJob de ménage ne la lançait qu'une
 * fois par jour. Une session vit une heure (`session.ttl`), mais restait donc
 * jusqu'à 25 h en base : chaque `initialize` anonyme y écrit une ligne. Purgée
 * chaque heure par un CronJob à elle, une ligne expirée vit au plus deux
 * heures ; le ménage quotidien ne la lance plus (une seule source).
 */
final class McpSessionsPrunedHourlyTest extends KernelTestCase
{
    private const string MANIFEST = 'cache-prune-cronjob.yaml';

    private const string PRUNE = 'cache:pool:prune';

    private const string EXPIRED_KEY = 'mcp-prune-test-expired';

    private const string LIVE_KEY = 'mcp-prune-test-live';

    protected function tearDown(): void
    {
        $this->connection()->executeStatement("DELETE FROM cache_items WHERE item_id LIKE '%mcp-prune-test-%'");
        parent::tearDown();
    }

    /** Une ligne du pool des sessions expirée part à la purge, une ligne vivante reste. */
    public function testCachePoolPruneRemovesAnExpiredSessionRow(): void
    {
        $pool = $this->sessionPool();
        foreach ([self::EXPIRED_KEY, self::LIVE_KEY] as $key) {
            $pool->save($pool->getItem($key)->set('session')->expiresAfter(3600));
        }
        // Vieillie de deux heures en base, plutôt qu'attendue.
        $this->connection()->executeStatement(
            "UPDATE cache_items SET item_time = item_time - 7200 WHERE item_id LIKE '%".self::EXPIRED_KEY."'",
        );
        self::assertSame(2, $this->rows());

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find(self::PRUNE));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame(1, $this->rows());
        self::assertTrue($this->sessionPool()->getItem(self::LIVE_KEY)->isHit());
    }

    public function testAnHourlyCronJobRunsCachePoolPrune(): void
    {
        $manifests = KubernetesManifests::base();

        $spec = $manifests->resource(self::MANIFEST, 'CronJob')['spec'] ?? null;
        self::assertIsArray($spec);
        self::assertMatchesRegularExpression('/\A([0-9]|[1-5][0-9]) \* \* \* \*\z/', KubernetesManifests::text($spec['schedule'] ?? null), 'Une minute fixe, chaque heure.');
        self::assertSame('Forbid', KubernetesManifests::text($spec['concurrencyPolicy'] ?? null), 'Deux purges ne se chevauchent pas.');

        self::assertSame([self::MANIFEST], $this->manifestsRunningThePrune($manifests), 'Une seule source pour la purge du cache.');
    }

    /** `kubectl apply -k` n'applique que ce que la kustomization liste. */
    public function testTheCronJobIsAKustomizeResource(): void
    {
        $kustomization = KubernetesManifests::base()->resource('kustomization.yaml', 'Kustomization');

        self::assertContains(self::MANIFEST, $kustomization['resources'] ?? []);
    }

    /**
     * Les manifestes de l'image backend dont un conteneur lance la purge.
     *
     * @return list<string>
     */
    private function manifestsRunningThePrune(KubernetesManifests $manifests): array
    {
        $running = [];
        foreach ($manifests->manifestsRunningTheBackendImage() as $manifest) {
            foreach ($manifests->backendContainersIn($manifest) as $container) {
                if (str_contains(json_encode($container['command'] ?? [], \JSON_THROW_ON_ERROR), self::PRUNE)) {
                    $running[] = $manifest;
                }
            }
        }

        return $running;
    }

    private function sessionPool(): CacheItemPoolInterface
    {
        return self::getContainer()->get('cache.mcp_sessions');
    }

    private function rows(): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM cache_items WHERE item_id LIKE '%mcp-prune-test-%'");
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
