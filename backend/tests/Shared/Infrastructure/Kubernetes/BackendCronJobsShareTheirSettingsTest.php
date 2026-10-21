<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * Les CronJobs qui exécutent l'image backend (`cache-prune`, `messenger-purge`,
 * `watch-refresh`) recopient les mêmes réglages : politique de concurrence,
 * historiques, reprises, compte de service, `securityContext`, initContainer
 * `seed-cache-system`, volumes, variables, montages (#673, point 10).
 *
 * La copie est gardée **par décision** (2026-10-21), plutôt que factorisée
 * dans un patch kustomize : chaque manifeste se lit seul, et les gardes qui
 * exigent ces réglages dans chaque fichier (`SystemCachePodVolumeTest`,
 * `ConsoleLockWaitBoundTest`) lisent les fichiers de `k8s/base`, jamais le
 * rendu de kustomize, absent du conteneur de dev. Ce test empêche qu'elle
 * dérive : un réglage changé dans un seul CronJob le fait rougir.
 *
 * Ce qui appartient à chaque tâche reste libre : nom et étiquettes, planning,
 * `activeDeadlineSeconds` (une commande qui attend des tiers a droit à plus),
 * nom, commande et ressources du conteneur.
 */
final class BackendCronJobsShareTheirSettingsTest extends TestCase
{
    /** Clés du conteneur propres à chaque tâche. */
    private const array PER_JOB_CONTAINER_KEYS = ['name', 'command', 'resources'];

    /**
     * Un CronJob qui cesserait d'exécuter l'image backend sortirait de la
     * comparaison sans rien dire : chaque CronJob de `k8s/base` doit y être.
     */
    public function testEveryCronJobOfTheBaseIsCompared(): void
    {
        $manifests = KubernetesManifests::base();
        $paths = glob($manifests->directory().'/*.y*ml');
        self::assertIsArray($paths);

        $withCronJob = [];
        foreach ($paths as $path) {
            $manifest = basename($path);
            if ([] !== $this->cronJobsOf($manifests, $manifest)) {
                $withCronJob[] = $manifest;
            }
        }
        sort($withCronJob);

        self::assertSame($withCronJob, array_keys($this->sharedSettingsByManifest()), 'Un CronJob de k8s/base n\'exécute pas l\'image backend : il échappe à la comparaison des réglages communs.');
    }

    public function testEveryBackendCronJobSharesTheSameSettings(): void
    {
        $settings = $this->sharedSettingsByManifest();

        self::assertGreaterThanOrEqual(2, \count($settings), 'Moins de deux CronJobs backend : le test ne compare plus rien.');

        $reference = array_key_first($settings);
        self::assertIsString($reference);
        foreach ($settings as $manifest => $shared) {
            self::assertSame(
                $settings[$reference],
                $shared,
                \sprintf('%s ne reprend pas les réglages communs de %s : les aligner, ou sortir explicitement le réglage des parts communes de ce test.', $manifest, $reference),
            );
        }
    }

    /**
     * Les parts communes de chaque CronJob backend, par manifeste.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sharedSettingsByManifest(): array
    {
        $manifests = KubernetesManifests::base();

        $settings = [];
        foreach ($manifests->manifestsRunningTheBackendImage() as $manifest) {
            if ([] === $this->cronJobsOf($manifests, $manifest)) {
                continue;
            }

            $settings[$manifest] = $this->sharedSettingsOf($manifests->resource($manifest, 'CronJob'), $manifest);
        }

        return $settings;
    }

    /**
     * @return array<mixed>
     */
    private function cronJobsOf(KubernetesManifests $manifests, string $manifest): array
    {
        return array_filter($manifests->documents($manifest), static fn (array $resource): bool => 'CronJob' === ($resource['kind'] ?? null));
    }

    /**
     * @param array<mixed> $cronJob
     *
     * @return array<string, mixed>
     */
    private function sharedSettingsOf(array $cronJob, string $manifest): array
    {
        $spec = $cronJob['spec'] ?? null;
        self::assertIsArray($spec, \sprintf('%s : CronJob sans spec.', $manifest));
        $jobSpec = $spec['jobTemplate']['spec'] ?? null;
        self::assertIsArray($jobSpec, \sprintf('%s : CronJob sans jobTemplate.spec.', $manifest));

        $podSpec = KubernetesManifests::podSpecOf($cronJob);
        self::assertNotNull($podSpec, \sprintf('%s : CronJob sans pod spec.', $manifest));

        $containers = KubernetesManifests::containers($podSpec);
        self::assertCount(1, $containers, \sprintf('%s : un seul conteneur attendu, la commande de la tâche.', $manifest));

        unset($podSpec['containers']);

        return [
            'concurrencyPolicy' => $spec['concurrencyPolicy'] ?? null,
            'successfulJobsHistoryLimit' => $spec['successfulJobsHistoryLimit'] ?? null,
            'failedJobsHistoryLimit' => $spec['failedJobsHistoryLimit'] ?? null,
            'backoffLimit' => $jobSpec['backoffLimit'] ?? null,
            'podSpec' => $podSpec,
            'container' => array_diff_key($containers[0], array_flip(self::PER_JOB_CONTAINER_KEYS)),
        ];
    }
}
