<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

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
     * Le pipeline attend le Job 300 s (`wait-rollout.sh … --timeout 300`), sonde
     * comprise : 90 s d'attente, plus les pulls et le démarrage du pod. Le délai
     * du Job est au-dessus (issue #535) : un pod bloqué tourne encore quand
     * `run-job.sh` lit ses journaux. La relation : tools/tests/run-job.test.sh.
     */
    public function testTheJobIsBoundedAndNeverRetried(): void
    {
        $spec = $this->job()['spec'] ?? null;
        self::assertIsArray($spec);

        self::assertSame(0, $spec['backoffLimit'] ?? null);
        self::assertSame(360, $spec['activeDeadlineSeconds'] ?? null);
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
        self::assertStringNotContainsString(self::MANIFEST, KubernetesManifests::base()->source('kustomization.yaml'));
    }

    /**
     * @return array<mixed>
     */
    private function container(): array
    {
        $containers = KubernetesManifests::containers($this->podSpec());
        self::assertCount(1, $containers);

        return $containers[0];
    }

    /**
     * @return array<mixed>
     */
    private function podSpec(): array
    {
        return KubernetesManifests::base()->podSpec(self::MANIFEST, 'Job');
    }

    /**
     * @return array<mixed>
     */
    private function job(): array
    {
        return KubernetesManifests::base()->resource(self::MANIFEST, 'Job');
    }

    private function source(): string
    {
        return KubernetesManifests::base()->source(self::MANIFEST);
    }
}
