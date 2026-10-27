<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * La purge des connexions MCP et de leurs jetons tourne chaque nuit (#649),
 * dans le CronJob de ménage quotidien, et nulle part ailleurs.
 *
 * Sans elle, les tables des jetons grossissent d'une ligne par heure et par
 * connexion (un jeton d'accès vit une heure, un rafraîchissement tourne à
 * chaque usage), et une connexion expirée n'est jamais marquée : son audit
 * `mcp-connection-revoked` (cause `expired`) n'est écrit que par la purge.
 */
final class McpConnectionsPurgedDailyTest extends TestCase
{
    private const string MANIFEST = 'messenger-purge-cronjob.yaml';

    private const string CONTAINER = 'purge';

    private const string PURGE = 'app:mcp:purge';

    private const string PURGE_LINE = 'php bin/console app:mcp:purge --no-interaction';

    /**
     * Le conteneur `purge` du ménage quotidien lance la commande, sous
     * `set -e` (un échec fait échouer le Job, que `backoffLimit` relance),
     * et ses attentes de verrou sont bornées comme sous FPM : la purge
     * verrouille des connexions qu'un rafraîchissement peut tenir.
     */
    public function testTheDailyHousekeepingCronJobRunsThePurge(): void
    {
        $manifests = KubernetesManifests::base();

        $spec = $manifests->resource(self::MANIFEST, 'CronJob')['spec'] ?? null;
        self::assertIsArray($spec);
        self::assertMatchesRegularExpression('/\A[0-9]{1,2} [0-9]{1,2} \* \* \*\z/', KubernetesManifests::text($spec['schedule'] ?? null), 'Une fois par jour.');

        $container = $manifests->container(self::MANIFEST, 'CronJob', self::CONTAINER);
        $command = $container['command'] ?? null;
        self::assertIsArray($command);
        self::assertSame(['sh', '-c'], \array_slice($command, 0, 2), 'Un script shell, `sh -c`.');
        $lines = array_map(trim(...), explode("\n", KubernetesManifests::text($command[2] ?? null)));
        $setE = array_search('set -e', $lines, true);
        $purge = array_search(self::PURGE_LINE, $lines, true);
        self::assertIsInt($purge, 'La purge des connexions MCP n\'est pas lancée par le ménage quotidien.');
        self::assertIsInt($setE, 'Le script doit tourner sous `set -e`.');
        self::assertLessThan($purge, $setE, '`set -e` doit précéder la purge.');

        $pgOptions = KubernetesManifests::firstWith($container, 'env', 'name', 'PGOPTIONS');
        self::assertNotNull($pgOptions, 'PGOPTIONS absent du conteneur de purge.');
        self::assertStringContainsString('-c lock_timeout=5s', KubernetesManifests::text($pgOptions['value'] ?? null));
    }

    /** Une seule source : aucun autre manifeste de l'image backend ne lance la purge. */
    public function testNoOtherManifestRunsThePurge(): void
    {
        $manifests = KubernetesManifests::base();
        $running = [];
        foreach ($manifests->manifestsRunningTheBackendImage() as $manifest) {
            foreach (['containers', 'initContainers'] as $list) {
                foreach ($manifests->backendContainersIn($manifest, $list) as $container) {
                    $invocation = json_encode([$container['command'] ?? [], $container['args'] ?? []], \JSON_THROW_ON_ERROR);
                    if (str_contains($invocation, self::PURGE)) {
                        $running[] = $manifest;
                    }
                }
            }
        }

        self::assertSame([self::MANIFEST], $running);
    }
}
