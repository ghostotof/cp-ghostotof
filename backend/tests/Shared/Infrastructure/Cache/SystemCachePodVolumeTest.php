<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
    private const string MOUNT_PATH = '/var/www/backend/var/cache/prod/pools/system';

    /**
     * Les six manifestes qui exécutent l'image backend. La liste est vérifiée
     * dans l'autre sens par testNoOtherManifestRunsTheBackendImage().
     */
    private const array MANIFESTS = [
        'backend-deployment.yaml',
        'messenger-worker-deployment.yaml',
        'watch-refresh-cronjob.yaml',
        'messenger-purge-cronjob.yaml',
        'migrate-job.yaml',
        'seed-job.yaml',
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

        self::assertNotNull($volume, sprintf('%s : volume « %s » absent.', $manifest, self::VOLUME));
        $emptyDir = $volume['emptyDir'] ?? null;
        self::assertIsArray($emptyDir, sprintf('%s : « %s » doit être un emptyDir.', $manifest, self::VOLUME));
        // Borné : un cache qui grossirait sans fin remplirait le disque du nœud.
        self::assertArrayHasKey('sizeLimit', $emptyDir, sprintf('%s : emptyDir sans sizeLimit.', $manifest));
    }

    #[DataProvider('manifests')]
    public function testEveryBackendContainerMountsTheSystemCacheWritable(string $manifest): void
    {
        $containers = $this->backendContainers($this->podSpec($manifest)['containers'] ?? null);
        self::assertNotSame([], $containers, sprintf('%s : aucun conteneur de l\'image backend.', $manifest));

        foreach ($containers as $container) {
            $mount = $this->firstWith($container['volumeMounts'] ?? null, 'mountPath', self::MOUNT_PATH);
            self::assertNotNull($mount, sprintf('%s, conteneur %s : %s non monté.', $manifest, $this->text($container['name'] ?? null), self::MOUNT_PATH));
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
            $this->backendContainers($this->podSpec($manifest)['initContainers'] ?? null),
            fn (array $container): bool => null !== $this->firstWith($container['volumeMounts'] ?? null, 'name', self::VOLUME)
                && str_contains($this->text($container['command'] ?? null), self::MOUNT_PATH.'/.'),
        );

        self::assertCount(1, $seeders, sprintf('%s : il faut exactement un initContainer qui copie %s/. dans le volume.', $manifest, self::MOUNT_PATH));
    }

    public function testNoOtherManifestRunsTheBackendImage(): void
    {
        $paths = glob($this->k8sBase().'/*.yaml');
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
        $path = $this->k8sBase().'/'.$manifest;
        self::assertFileIsReadable($path);
        $documents = preg_split('/^---\s*$/m', (string) file_get_contents($path));
        self::assertIsArray($documents);

        foreach ($documents as $document) {
            $resource = Yaml::parse($document);
            if (!\is_array($resource)) {
                continue;
            }
            $spec = match ($resource['kind'] ?? null) {
                'Deployment', 'Job' => $this->at($resource, 'spec', 'template', 'spec'),
                'CronJob' => $this->at($resource, 'spec', 'jobTemplate', 'spec', 'template', 'spec'),
                default => null,
            };
            if (null !== $spec) {
                return $spec;
            }
        }

        self::fail(sprintf('%s : aucun pod spec trouvé.', $manifest));
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>|null
     */
    private function at(array $node, string ...$keys): ?array
    {
        foreach ($keys as $key) {
            $child = $node[$key] ?? null;
            if (!\is_array($child)) {
                return null;
            }
            $node = $child;
        }

        return $node;
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
     * @return list<array<mixed>>
     */
    private function backendContainers(mixed $containers): array
    {
        return array_values(array_filter(
            $this->items($containers),
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

    private function k8sBase(): string
    {
        return \dirname(__DIR__, 5).'/k8s/base';
    }
}
