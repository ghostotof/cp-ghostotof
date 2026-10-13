<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #575 : les migrations tournent sous le rôle propriétaire du schéma
 * (`cpg_migrator`), l'application sous un rôle qui n'a que le DML (`cpg_app`).
 * Deux `DATABASE_URL`, donc deux Secrets : `backend-secrets` (l'application,
 * monté par tout ce qui tourne l'image backend) et `backend-migration-secrets`,
 * que le seul Job de migration lit — par `env`, qui prime sur `envFrom`, pour
 * que `backend-secrets` reste monté (APP_SECRET et le reste du noyau).
 *
 * Ce test pince le câblage : le Job lit l'URL de migration, aucun autre pod
 * de l'image backend ne voit ce Secret, et chaque overlay déclare
 * l'ExternalSecret avec sa clé Scaleway, à côté de celui de l'application.
 */
final class MigrationRoleSecretsTest extends TestCase
{
    private const string MIGRATE_JOB = 'migrate-job.yaml';
    private const string MIGRATION_SECRET = 'backend-migration-secrets';
    private const string APPLICATION_SECRET = 'backend-secrets';

    public function testTheMigrationJobReadsItsDatabaseUrlFromTheMigrationSecret(): void
    {
        $container = KubernetesManifests::base()->container(self::MIGRATE_JOB, 'Job', 'migrate');

        $url = KubernetesManifests::firstWith($container, 'env', 'name', 'DATABASE_URL');
        self::assertNotNull($url, 'Le Job de migration lit DATABASE_URL dans son propre Secret.');
        self::assertSame(['name' => self::MIGRATION_SECRET, 'key' => 'DATABASE_URL'], $url['valueFrom']['secretKeyRef'] ?? null);
        self::assertArrayNotHasKey('value', $url);

        self::assertContains(['secretRef' => ['name' => self::APPLICATION_SECRET]], KubernetesManifests::entries($container, 'envFrom'), 'Le reste des secrets du noyau vient toujours de backend-secrets.');
    }

    /** L'URL du propriétaire n'atteint que la migration : ni FPM, ni le worker, ni un CronJob, ni un autre Job. */
    public function testNoOtherBackendWorkloadSeesTheMigrationSecret(): void
    {
        $base = KubernetesManifests::base();
        $manifests = $base->manifestsRunningTheBackendImage();
        self::assertContains(self::MIGRATE_JOB, $manifests);

        foreach ($manifests as $manifest) {
            if (self::MIGRATE_JOB === $manifest) {
                continue;
            }
            self::assertStringNotContainsString(self::MIGRATION_SECRET, $base->source($manifest), $manifest);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOverlays(): iterable
    {
        yield 'preprod' => ['preprod'];
        yield 'prod' => ['prod'];
    }

    #[DataProvider('provideOverlays')]
    public function testTheOverlayDeclaresTheMigrationSecretBesideTheApplicationOne(string $overlay): void
    {
        $secrets = $this->externalSecrets($overlay);

        $migration = $secrets[self::MIGRATION_SECRET] ?? null;
        self::assertNotNull($migration, \sprintf('%s : l\'ExternalSecret %s manque.', $overlay, self::MIGRATION_SECRET));
        self::assertSame(self::MIGRATION_SECRET, $migration['spec']['target']['name'] ?? null);
        self::assertSame(
            [['secretKey' => 'DATABASE_URL', 'remoteRef' => ['key' => \sprintf('name:%s-backend-migration-database-url', $overlay)]]],
            $migration['spec']['data'] ?? null,
            'Une seule clé : l\'URL du rôle de migration, sous son propre nom Scaleway.',
        );
        self::assertArrayNotHasKey('annotations', $migration['metadata'] ?? [], 'Jamais `deploy-gate: optional` : le Job de migration en a besoin pour démarrer.');

        $application = $secrets[self::APPLICATION_SECRET] ?? null;
        self::assertNotNull($application);
        self::assertSame($application['spec']['secretStoreRef'] ?? null, $migration['spec']['secretStoreRef'] ?? null);
        self::assertSame($application['spec']['refreshInterval'] ?? null, $migration['spec']['refreshInterval'] ?? null);
        self::assertContains(
            ['secretKey' => 'DATABASE_URL', 'remoteRef' => ['key' => \sprintf('name:%s-backend-database-url', $overlay)]],
            KubernetesManifests::entries($application['spec'] ?? [], 'data'),
            'backend-secrets garde DATABASE_URL : celle du rôle applicatif.',
        );
    }

    /**
     * Les ExternalSecret de l'overlay, par nom.
     *
     * @return array<string, array<mixed>>
     */
    private function externalSecrets(string $overlay): array
    {
        $byName = [];
        foreach (KubernetesManifests::overlay($overlay)->documents('external-secrets.yaml') as $document) {
            if ('ExternalSecret' !== ($document['kind'] ?? null)) {
                continue;
            }
            $name = $document['metadata']['name'] ?? null;
            self::assertIsString($name);
            $byName[$name] = $document;
        }

        return $byName;
    }
}
