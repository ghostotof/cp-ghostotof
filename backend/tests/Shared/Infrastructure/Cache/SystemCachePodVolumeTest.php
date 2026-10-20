<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Cache;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `cache.system` doit rester inscriptible dans chaque pod qui exécute l'image
 * backend (issue #288, ADR 0005 amendé le 2026-09-30).
 *
 * Les pods tournent en `readOnlyRootFilesystem`. `cache.system` est un
 * PhpFilesAdapter sous `var/cache/prod/pools/system` : le build le préchauffe,
 * mais une partie des clés (property-info, serializer, métadonnées API
 * Platform, requêtes DQL analysées) ne s'écrit qu'à l'exécution. Sur un disque
 * en lecture seule, chacune de ces écritures échouait à chaque requête, avec un
 * avertissement dans les logs, et le cache ne servait jamais.
 *
 * Chaque pod monte donc un emptyDir sur ce répertoire, rempli au démarrage par
 * un initContainer qui y copie le cache préchauffé de l'image. Ce test vérifie
 * les trois moitiés du dispositif sur chaque pod spec de chaque manifeste — une
 * seconde charge ajoutée à un fichier compris : le volume (borné en taille), le
 * montage inscriptible dans chaque conteneur de l'image backend, et la copie.
 * Un pod ajouté sans elles, ou un montage oublié, fait rougir la suite.
 */
final class SystemCachePodVolumeTest extends TestCase
{
    private const string VOLUME = 'cache-system';
    /**
     * Le répertoire du cache système dans l'image ; ProductionImageWarmUpTest
     * vérifie que le build contrôle ce même répertoire.
     */
    public const string MOUNT_PATH = '/var/www/backend/var/cache/prod/pools/system';

    /**
     * Les manifestes qui exécutent l'image backend. La liste est vérifiée dans
     * l'autre sens par testNoOtherManifestRunsTheBackendImage(), sur le
     * recensement de KubernetesManifests.
     */
    private const array MANIFESTS = [
        'backend-deployment.yaml',
        'messenger-worker-deployment.yaml',
        'watch-refresh-cronjob.yaml',
        'messenger-purge-cronjob.yaml',
        'cache-prune-cronjob.yaml',
        'migrate-job.yaml',
        'seed-job.yaml',
        'create-user-job.yaml',
        'messenger-probe-job.yaml',
        'messenger-stop-workers-job.yaml',
        'messenger-failed-retry-job.yaml',
        'messenger-failed-remove-job.yaml',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function manifests(): iterable
    {
        foreach (self::MANIFESTS as $manifest) {
            yield $manifest => [$manifest];
        }
    }

    #[DataProvider('manifests')]
    public function testThePodDeclaresABoundedEmptyDirForTheSystemCache(string $manifest): void
    {
        foreach ($this->podSpecs($manifest) as $where => $podSpec) {
            $volume = KubernetesManifests::firstWith($podSpec, 'volumes', 'name', self::VOLUME);

            self::assertNotNull($volume, \sprintf('%s : volume « %s » absent.', $where, self::VOLUME));
            $emptyDir = $volume['emptyDir'] ?? null;
            self::assertIsArray($emptyDir, \sprintf('%s : « %s » doit être un emptyDir.', $where, self::VOLUME));
            // Borné : un cache qui grossirait sans fin remplirait le disque du nœud.
            self::assertArrayHasKey('sizeLimit', $emptyDir, \sprintf('%s : emptyDir sans sizeLimit.', $where));
        }
    }

    #[DataProvider('manifests')]
    public function testEveryBackendContainerMountsTheSystemCacheWritable(string $manifest): void
    {
        foreach ($this->podSpecs($manifest) as $where => $podSpec) {
            $containers = KubernetesManifests::backendContainers($podSpec);
            self::assertNotSame([], $containers, \sprintf('%s : aucun conteneur de l\'image backend.', $where));

            foreach ($containers as $container) {
                $mount = KubernetesManifests::firstWith($container, 'volumeMounts', 'mountPath', self::MOUNT_PATH);
                self::assertNotNull($mount, \sprintf('%s, conteneur %s : %s non monté.', $where, KubernetesManifests::text($container['name'] ?? null), self::MOUNT_PATH));
                self::assertSame(self::VOLUME, $mount['name'] ?? null);
                self::assertNotTrue($mount['readOnly'] ?? false, 'Le cache doit rester inscriptible.');
            }
        }
    }

    /**
     * Sans la copie, l'emptyDir masquerait le cache préchauffé de l'image et
     * chaque pod repartirait d'un cache vide.
     */
    #[DataProvider('manifests')]
    public function testAnInitContainerSeedsTheVolumeFromThePrewarmedCache(string $manifest): void
    {
        foreach ($this->podSpecs($manifest) as $where => $podSpec) {
            $seeders = array_filter(
                KubernetesManifests::backendContainers($podSpec, 'initContainers'),
                static fn (array $container): bool => null !== KubernetesManifests::firstWith($container, 'volumeMounts', 'name', self::VOLUME)
                    && str_contains(KubernetesManifests::text($container['command'] ?? null), self::MOUNT_PATH.'/.'),
            );

            self::assertCount(1, $seeders, \sprintf('%s : il faut exactement un initContainer qui copie %s/. dans le volume.', $where, self::MOUNT_PATH));
        }
    }

    public function testNoOtherManifestRunsTheBackendImage(): void
    {
        $found = KubernetesManifests::base()->manifestsRunningTheBackendImage();
        $expected = self::MANIFESTS;
        sort($expected);

        self::assertSame($expected, $found, 'Un manifeste exécute l\'image backend sans être couvert par ce test.');
    }

    /**
     * Chaque pod spec du manifeste, sous une clé qui le désigne dans les
     * messages (`seed-job.yaml, pod spec 1`).
     *
     * @return array<string, array<mixed>>
     */
    private function podSpecs(string $manifest): array
    {
        $podSpecs = KubernetesManifests::base()->podSpecs($manifest);
        self::assertNotSame([], $podSpecs, \sprintf('%s : aucun pod spec trouvé.', $manifest));

        $named = [];
        foreach ($podSpecs as $index => $podSpec) {
            $named[\sprintf('%s, pod spec %d', $manifest, $index + 1)] = $podSpec;
        }

        return $named;
    }
}
