<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Les manifestes de `k8s/base`, tels que les tests les lisent (issue #541) :
 * un fichier découpé en documents, chacun une ressource, et la descente
 * jusqu'au pod spec puis à un conteneur nommé. Écrit une fois pour que chaque
 * test lise le même format de la même façon ; les assertions métier restent
 * dans les tests.
 *
 * Le composant Yaml refuse plusieurs documents dans une même chaîne : le
 * fichier est découpé ici, sur `---` seul ou suivi d'un commentaire. Un
 * document vide (séparateur en tête ou en fin de fichier, commentaires seuls)
 * est sauté ; un document illisible ou qui n'est pas une table fait échouer le
 * test, au lieu d'être ignoré et de laisser passer un manifeste mal indenté.
 *
 * Les overlays ne sont pas lus : seule la base l'est, sans `kustomize build`.
 */
final readonly class KubernetesManifests
{
    private const string DOCUMENT_SEPARATOR = '/^---[ \t]*(?:#.*)?$/m';

    private function __construct(
        private string $directory,
    ) {
    }

    /**
     * Les manifestes du dépôt, `k8s/base`.
     */
    public static function base(): self
    {
        return new self(\dirname(__DIR__, 3).'/k8s/base');
    }

    /**
     * Les manifestes d'un autre répertoire : ceux qu'un test écrit pour
     * éprouver la lecture elle-même.
     */
    public static function in(string $directory): self
    {
        return new self($directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Le texte d'un manifeste, chemin relatif au répertoire.
     */
    public function source(string $manifest): string
    {
        $path = $this->directory.'/'.$manifest;
        Assert::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }

    /**
     * Chaque ressource du manifeste, dans l'ordre du fichier.
     *
     * @return list<array<mixed>>
     */
    public function documents(string $manifest): array
    {
        $documents = preg_split(self::DOCUMENT_SEPARATOR, $this->source($manifest));
        Assert::assertIsArray($documents);

        $resources = [];
        foreach ($documents as $index => $document) {
            $where = \sprintf('%s, document %d', $manifest, $index + 1);
            try {
                $resource = Yaml::parse($document);
            } catch (ParseException $exception) {
                Assert::fail(\sprintf('%s illisible : %s', $where, $exception->getMessage()));
            }
            if (null === $resource) {
                continue;
            }
            if (!\is_array($resource) || array_is_list($resource)) {
                Assert::fail(\sprintf('%s : une ressource est une table YAML, %s lu.', $where, get_debug_type($resource)));
            }
            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * Le seul document du manifeste qui a ce `kind`.
     *
     * @return array<mixed>
     */
    public function resource(string $manifest, string $kind): array
    {
        $matching = array_values(array_filter(
            $this->documents($manifest),
            static fn (array $resource): bool => $kind === ($resource['kind'] ?? null),
        ));
        Assert::assertCount(1, $matching, \sprintf('%s : %d document(s) de kind %s, un seul attendu.', $manifest, \count($matching), $kind));

        return $matching[0];
    }

    /**
     * Le pod spec de la seule ressource de ce `kind`.
     *
     * @return array<mixed>
     */
    public function podSpec(string $manifest, string $kind): array
    {
        $podSpec = self::podSpecOf($this->resource($manifest, $kind));
        Assert::assertNotNull($podSpec, \sprintf('%s : le %s n\'a pas de pod spec.', $manifest, $kind));

        return $podSpec;
    }

    /**
     * Les pod specs de toutes les charges du manifeste, dans l'ordre du
     * fichier ; les autres ressources (Service, PVC…) n'en ont pas.
     *
     * @return list<array<mixed>>
     */
    public function podSpecs(string $manifest): array
    {
        $podSpecs = [];
        foreach ($this->documents($manifest) as $resource) {
            $podSpec = self::podSpecOf($resource);
            if (null !== $podSpec) {
                $podSpecs[] = $podSpec;
            }
        }

        return $podSpecs;
    }

    /**
     * Le conteneur nommé de la ressource de ce `kind`.
     *
     * @return array<mixed>
     */
    public function container(string $manifest, string $kind, string $name): array
    {
        return $this->named($manifest, $kind, $name, 'containers', 'conteneur');
    }

    /**
     * L'initContainer nommé de la ressource de ce `kind`.
     *
     * @return array<mixed>
     */
    public function initContainer(string $manifest, string $kind, string $name): array
    {
        return $this->named($manifest, $kind, $name, 'initContainers', 'initContainer');
    }

    /**
     * Le pod spec d'une ressource, ou null si elle n'en porte pas :
     * `spec.template.spec` pour un Deployment, un StatefulSet ou un Job,
     * `spec.jobTemplate.spec.template.spec` pour un CronJob.
     *
     * @param array<mixed> $resource
     *
     * @return array<mixed>|null
     */
    public static function podSpecOf(array $resource): ?array
    {
        return match ($resource['kind'] ?? null) {
            'Deployment', 'StatefulSet', 'Job' => self::at($resource, 'spec', 'template', 'spec'),
            'CronJob' => self::at($resource, 'spec', 'jobTemplate', 'spec', 'template', 'spec'),
            default => null,
        };
    }

    /**
     * Les éléments d'une liste de conteneurs du pod spec qui sont des tables.
     *
     * @param array<mixed>                  $podSpec
     * @param 'containers'|'initContainers' $list
     *
     * @return list<array<mixed>>
     */
    public static function containers(array $podSpec, string $list = 'containers'): array
    {
        $containers = $podSpec[$list] ?? null;

        return \is_array($containers) ? array_values(array_filter($containers, \is_array(...))) : [];
    }

    /**
     * @param 'containers'|'initContainers' $list
     *
     * @return array<mixed>
     */
    private function named(string $manifest, string $kind, string $name, string $list, string $what): array
    {
        $matching = array_values(array_filter(
            self::containers($this->podSpec($manifest, $kind), $list),
            static fn (array $container): bool => $name === ($container['name'] ?? null),
        ));
        Assert::assertCount(1, $matching, \sprintf('%s : %s « %s » absent du %s.', $manifest, $what, $name, $kind));

        return $matching[0];
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>|null
     */
    private static function at(array $node, string ...$keys): ?array
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
}
