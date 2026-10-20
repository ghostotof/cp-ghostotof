<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Session;

use App\Tests\Support\KubernetesManifests;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les sessions MCP expirées quittent `cache_items` dans l'heure (#644).
 *
 * L'adaptateur DBAL n'efface jamais une ligne expirée de lui-même : seul
 * `cache:pool:prune` le fait, et le CronJob de ménage ne la lançait qu'une
 * fois par jour. Une session vit une heure (`session.ttl`), mais restait donc
 * jusqu'à 25 h en base : chaque `initialize` anonyme y écrit une ligne, et la
 * zone nginx `mcp` en admet une par requête. Purgée chaque heure, une ligne
 * expirée vit au plus deux heures.
 */
final class McpSessionsPrunedHourlyTest extends KernelTestCase
{
    private const string MANIFEST = 'cache-prune-cronjob.yaml';

    private const string PRUNE = 'cache:pool:prune';

    public function testCachePoolPruneCoversTheMcpSessionPool(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find(self::PRUNE));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('cache.mcp_sessions', $tester->getDisplay());
    }

    public function testAnHourlyCronJobRunsCachePoolPrune(): void
    {
        $manifests = KubernetesManifests::base();

        $cronJob = $manifests->resource(self::MANIFEST, 'CronJob');
        $schedule = KubernetesManifests::text($cronJob['spec']['schedule'] ?? null);
        self::assertMatchesRegularExpression('/\A([0-9]|[1-5][0-9]) \* \* \* \*\z/', $schedule, 'Une minute fixe, chaque heure.');

        $commands = array_map(
            static fn (array $container): string => json_encode($container['command'] ?? [], \JSON_THROW_ON_ERROR),
            $manifests->backendContainersIn(self::MANIFEST),
        );
        self::assertCount(1, array_filter($commands, static fn (string $command): bool => str_contains($command, self::PRUNE)), 'Un conteneur de l\'image lance la purge.');
    }

    /** `kubectl apply -k` n'applique que ce que la kustomization liste. */
    public function testTheCronJobIsAKustomizeResource(): void
    {
        $kustomization = KubernetesManifests::base()->documents('kustomization.yaml')[0];

        self::assertContains(self::MANIFEST, $kustomization['resources'] ?? []);
    }
}
