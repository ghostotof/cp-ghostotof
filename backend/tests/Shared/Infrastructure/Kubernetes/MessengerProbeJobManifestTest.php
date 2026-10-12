<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le Job du message témoin (issue #470), lancé par tools/smoke-messenger-probe.sh
 * dans `smoke-test-preprod`.
 *
 * Il tourne l'image de la release avec les Secrets du backend, comme l'API :
 * il signe donc sous la même clé qu'elle, et un worker qui ne la partage pas
 * refuse le témoin. Il ne lit aucun Secret pour le pipeline (les variables
 * restent dans le pod) et n'envoie aucun mail.
 */
final class MessengerProbeJobManifestTest extends TestCase
{
    private const string MANIFEST = 'messenger-probe-job.yaml';

    public function testTheJobRunsTheProbeCommand(): void
    {
        self::assertSame(
            ['php', 'bin/console', 'app:messenger:probe', '--no-interaction', '--timeout=90'],
            $this->container()['command'] ?? null,
        );
    }

    /**
     * Le Job doit finir avant que le pipeline ne cesse de l'attendre
     * (`wait-rollout.sh … --timeout 300`), sonde comprise : 90 s d'attente,
     * plus les pulls et le démarrage du pod.
     */
    public function testTheJobIsBoundedAndNeverRetried(): void
    {
        $spec = $this->job()['spec'] ?? null;
        self::assertIsArray($spec);

        self::assertSame(0, $spec['backoffLimit'] ?? null);
        self::assertSame(240, $spec['activeDeadlineSeconds'] ?? null);
    }

    /** Les mêmes Secrets que l'API, donc la même clé de MAC qu'elle. */
    public function testTheJobSignsWithTheBackendSecrets(): void
    {
        self::assertSame(
            [['configMapRef' => ['name' => 'backend-config']], ['secretRef' => ['name' => 'backend-secrets']]],
            $this->container()['envFrom'] ?? null,
        );
    }

    public function testThePodIsHardenedLikeTheMigrationJob(): void
    {
        $spec = $this->podSpec();
        self::assertFalse($spec['automountServiceAccountToken'] ?? null);
        self::assertSame('Never', $spec['restartPolicy'] ?? null);
        self::assertSame([
            'runAsNonRoot' => true,
            'runAsUser' => 10001,
            'readOnlyRootFilesystem' => true,
            'allowPrivilegeEscalation' => false,
            'capabilities' => ['drop' => ['ALL']],
        ], $this->container()['securityContext'] ?? null);
    }

    /** Seule l'image est substituée, par tools/run-job.sh (lancé par tools/smoke-messenger-probe.sh). */
    public function testOnlyTheImageIsSubstituted(): void
    {
        preg_match_all('/\$\{([A-Z_]+)\}/', $this->source(), $matches);

        self::assertSame(['BACKEND_IMAGE'], array_values(array_unique($matches[1])));
    }

    /** Hors de `kustomization.yaml` : un `apply -k` ne lance aucun smoke. */
    public function testTheJobIsNotPartOfTheKustomization(): void
    {
        self::assertStringNotContainsString(self::MANIFEST, (string) file_get_contents($this->k8sBase().'/kustomization.yaml'));
    }

    /**
     * @return array<mixed>
     */
    private function container(): array
    {
        $containers = $this->podSpec()['containers'] ?? null;
        self::assertIsArray($containers);
        self::assertCount(1, $containers);
        $container = $containers[0];
        self::assertIsArray($container);

        return $container;
    }

    /**
     * @return array<mixed>
     */
    private function podSpec(): array
    {
        $spec = $this->job()['spec']['template']['spec'] ?? null;
        self::assertIsArray($spec);

        return $spec;
    }

    /**
     * @return array<mixed>
     */
    private function job(): array
    {
        $job = Yaml::parse($this->source());
        self::assertIsArray($job);
        self::assertSame('Job', $job['kind'] ?? null);

        return $job;
    }

    private function source(): string
    {
        $path = $this->k8sBase().'/'.self::MANIFEST;
        self::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }

    private function k8sBase(): string
    {
        return \dirname(__DIR__, 5).'/k8s/base';
    }
}
