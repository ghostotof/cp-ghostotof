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
 * Ce test pince le câblage : le Job lit l'URL de migration, aucun autre
 * manifeste — de la base, quelle que soit l'image, ni d'un overlay hors de la
 * déclaration de l'ExternalSecret — ne nomme ce Secret, et chaque overlay le
 * déclare avec sa clé Scaleway, à côté de celui de l'application.
 */
final class MigrationRoleSecretsTest extends TestCase
{
    private const string MIGRATE_JOB = 'migrate-job.yaml';
    private const string MIGRATION_SECRET = 'backend-migration-secrets';
    private const string APPLICATION_SECRET = 'backend-secrets';
    private const string EXTERNAL_SECRETS = 'external-secrets.yaml';
    private const array OVERLAYS = ['preprod', 'prod'];

    public function testTheMigrationJobReadsItsDatabaseUrlFromTheMigrationSecret(): void
    {
        $container = KubernetesManifests::base()->container(self::MIGRATE_JOB, 'Job', 'migrate');

        $url = KubernetesManifests::firstWith($container, 'env', 'name', 'DATABASE_URL');
        self::assertNotNull($url, 'Le Job de migration lit DATABASE_URL dans son propre Secret.');
        self::assertSame(['name' => self::MIGRATION_SECRET, 'key' => 'DATABASE_URL'], $url['valueFrom']['secretKeyRef'] ?? null);
        self::assertArrayNotHasKey('value', $url);

        self::assertContains(['secretRef' => ['name' => self::APPLICATION_SECRET]], KubernetesManifests::entries($container, 'envFrom'), 'Le reste des secrets du noyau vient toujours de backend-secrets.');
    }

    /**
     * L'URL du propriétaire n'atteint que la migration : aucun autre fichier de
     * la base (FPM, worker, CronJobs, Jobs, Adminer…) ni d'un overlay (un patch
     * du Deployment backend, par exemple) ne nomme le Secret — seule sa
     * déclaration ExternalSecret le peut.
     */
    public function testNoOtherManifestNamesTheMigrationSecret(): void
    {
        $base = KubernetesManifests::base();
        $offending = [];
        foreach ($this->yamlFilesOf($base->directory()) as $manifest) {
            if (self::MIGRATE_JOB !== $manifest && str_contains($base->source($manifest), self::MIGRATION_SECRET)) {
                $offending[] = 'k8s/base/'.$manifest;
            }
        }
        foreach (self::OVERLAYS as $overlay) {
            $files = KubernetesManifests::overlay($overlay);
            foreach ($this->yamlFilesOf($files->directory()) as $manifest) {
                if (self::EXTERNAL_SECRETS !== $manifest && str_contains($files->source($manifest), self::MIGRATION_SECRET)) {
                    $offending[] = \sprintf('k8s/overlays/%s/%s', $overlay, $manifest);
                }
            }
        }

        self::assertSame([], $offending, 'Seul migrate-job.yaml monte backend-migration-secrets.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOverlays(): iterable
    {
        foreach (self::OVERLAYS as $overlay) {
            yield $overlay => [$overlay];
        }
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
        self::assertNotSame('optional', $migration['metadata']['annotations']['cp-ghostotof.com/deploy-gate'] ?? null, 'Jamais `deploy-gate: optional` : le Job de migration en a besoin pour démarrer.');

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
     * Les fichiers YAML du répertoire, sans ses sous-répertoires, par nom.
     *
     * @return list<string>
     */
    private function yamlFilesOf(string $directory): array
    {
        $yaml = glob($directory.'/*.yaml');
        $yml = glob($directory.'/*.yml');
        self::assertIsArray($yaml);
        self::assertIsArray($yml);
        $files = array_map(basename(...), [...$yaml, ...$yml]);
        sort($files);

        return $files;
    }

    /**
     * Les ExternalSecret de l'overlay, par nom.
     *
     * @return array<string, array<mixed>>
     */
    private function externalSecrets(string $overlay): array
    {
        $byName = [];
        foreach (KubernetesManifests::overlay($overlay)->documents(self::EXTERNAL_SECRETS) as $document) {
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
