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
 * les trois moitiés du dispositif sur chaque pod spec : le volume (borné en
 * taille), le montage inscriptible dans chaque conteneur de l'image backend, et
 * la copie. Un pod ajouté sans elles, ou un montage oublié, fait rougir la suite.
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
     * Les huit manifestes qui exécutent l'image backend. La liste est vérifiée
     * dans l'autre sens par testNoOtherManifestRunsTheBackendImage().
     */
    private const array MANIFESTS = [
        'backend-deployment.yaml',
        'messenger-worker-deployment.yaml',
        'watch-refresh-cronjob.yaml',
        'messenger-purge-cronjob.yaml',
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
        $volume = $this->firstWith($this->podSpec($manifest)['volumes'] ?? null, 'name', self::VOLUME);

        self::assertNotNull($volume, \sprintf('%s : volume « %s » absent.', $manifest, self::VOLUME));
        $emptyDir = $volume['emptyDir'] ?? null;
        self::assertIsArray($emptyDir, \sprintf('%s : « %s » doit être un emptyDir.', $manifest, self::VOLUME));
        // Borné : un cache qui grossirait sans fin remplirait le disque du nœud.
        self::assertArrayHasKey('sizeLimit', $emptyDir, \sprintf('%s : emptyDir sans sizeLimit.', $manifest));
    }

    #[DataProvider('manifests')]
    public function testEveryBackendContainerMountsTheSystemCacheWritable(string $manifest): void
    {
        $containers = $this->backendContainers($this->podSpec($manifest), 'containers');
        self::assertNotSame([], $containers, \sprintf('%s : aucun conteneur de l\'image backend.', $manifest));

        foreach ($containers as $container) {
            $mount = $this->firstWith($container['volumeMounts'] ?? null, 'mountPath', self::MOUNT_PATH);
            self::assertNotNull($mount, \sprintf('%s, conteneur %s : %s non monté.', $manifest, $this->text($container['name'] ?? null), self::MOUNT_PATH));
            self::assertSame(self::VOLUME, $mount['name'] ?? null);
            self::assertNotTrue($mount['readOnly'] ?? false, 'Le cache doit rester inscriptible.');
        }
    }

    /**
     * Sans la copie, l'emptyDir masquerait le cache préchauffé de l'image et
     * chaque pod repartirait d'un cache vide.
     */
    #[DataProvider('manifests')]
    public function testAnInitContainerSeedsTheVolumeFromThePrewarmedCache(string $manifest): void
    {
        $seeders = array_filter(
            $this->backendContainers($this->podSpec($manifest), 'initContainers'),
            fn (array $container): bool => null !== $this->firstWith($container['volumeMounts'] ?? null, 'name', self::VOLUME)
                && str_contains($this->text($container['command'] ?? null), self::MOUNT_PATH.'/.'),
        );

        self::assertCount(1, $seeders, \sprintf('%s : il faut exactement un initContainer qui copie %s/. dans le volume.', $manifest, self::MOUNT_PATH));
    }

    public function testNoOtherManifestRunsTheBackendImage(): void
    {
        $paths = glob(KubernetesManifests::base()->directory().'/*.yaml');
        self::assertIsArray($paths);

        $found = [];
        foreach ($paths as $path) {
            if (1 === preg_match('/^\s+image:\s*(backend|\$\{BACKEND_IMAGE\})\s*$/m', (string) file_get_contents($path))) {
                $found[] = basename($path);
            }
        }
        sort($found);
        $expected = self::MANIFESTS;
        sort($expected);

        self::assertSame($expected, $found, 'Un manifeste exécute l\'image backend sans être couvert par ce test.');
    }

    /**
     * @return array<mixed>
     */
    private function podSpec(string $manifest): array
    {
        $podSpecs = KubernetesManifests::base()->podSpecs($manifest);
        self::assertNotSame([], $podSpecs, \sprintf('%s : aucun pod spec trouvé.', $manifest));

        return $podSpecs[0];
    }

    /**
     * Les éléments d'une liste YAML qui sont eux-mêmes des tables.
     *
     * @return list<array<mixed>>
     */
    private function items(mixed $list): array
    {
        return \is_array($list) ? array_values(array_filter($list, \is_array(...))) : [];
    }

    /**
     * @return array<mixed>|null
     */
    private function firstWith(mixed $list, string $key, string $value): ?array
    {
        foreach ($this->items($list) as $item) {
            if ($value === ($item[$key] ?? null)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<mixed>                  $podSpec
     * @param 'containers'|'initContainers' $list
     *
     * @return list<array<mixed>>
     */
    private function backendContainers(array $podSpec, string $list): array
    {
        return array_values(array_filter(
            KubernetesManifests::containers($podSpec, $list),
            static fn (array $container): bool => \in_array($container['image'] ?? null, ['backend', '${BACKEND_IMAGE}'], true),
        ));
    }

    /**
     * Une valeur YAML rendue lisible : chaîne telle quelle, liste jointe par
     * des espaces (une commande `['sh', '-c', '…']`), rien sinon.
     */
    private function text(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_array($value) ? implode(' ', array_filter($value, \is_string(...))) : '';
    }
}
