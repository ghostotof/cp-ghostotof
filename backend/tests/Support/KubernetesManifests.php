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
 * fichier est découpé ici, sur `---` seul ou suivi d'un commentaire, fins de
 * ligne Windows comprises. Un document vide (séparateur en tête ou en fin de
 * fichier, commentaires seuls) est sauté ; un document illisible, qui n'est
 * pas une table, ou une charge que la descente ne connaît pas fait échouer le
 * test, au lieu d'être ignoré et de laisser passer un manifeste mal lu.
 *
 * Un overlay se lit fichier par fichier, comme la base, jamais rendu par
 * `kustomize build` : un patch d'overlay sur la base reste invisible ici.
 */
final readonly class KubernetesManifests
{
    private const string DOCUMENT_SEPARATOR = '/^---[ \t]*(?:#[^\r\n]*)?\r?$/m';

    /** Les charges dont le pod spec est sous `spec.template.spec`. */
    private const array TEMPLATED_WORKLOADS = ['Deployment', 'StatefulSet', 'Job'];

    /**
     * L'image backend sous ses deux noms : `backend`, que l'`images:` de la
     * kustomization réécrit — par son nom, tag ou digest compris —, et le
     * placeholder des Jobs appliqués hors kustomize, que `envsubst` remplace
     * tel quel.
     */
    private const string BACKEND_IMAGE = '/\A(?:backend(?:[:@][^\s\/]+)?|\$\{BACKEND_IMAGE\})\z/';

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
     * Les fichiers d'un overlay, `k8s/overlays/<nom>`, tels qu'ils sont écrits.
     */
    public static function overlay(string $name): self
    {
        return new self(\dirname(self::base()->directory()).'/overlays/'.$name);
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
     * Le texte d'un fichier du répertoire, manifeste ou non (`rabbitmq.conf`).
     */
    public function source(string $file): string
    {
        $path = $this->directory.'/'.$file;
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
        $source = $this->source($manifest);
        $documents = preg_split(self::DOCUMENT_SEPARATOR, $source, -1, \PREG_SPLIT_OFFSET_CAPTURE);
        Assert::assertIsArray($documents);

        $resources = [];
        foreach ($documents as [$document, $offset]) {
            $where = \sprintf('%s, document de la ligne %d', $manifest, $this->firstLine($source, $document, $offset));
            try {
                $resource = Yaml::parse($document);
            } catch (ParseException $exception) {
                Assert::fail(\sprintf('%s illisible : %s', $where, $exception->getMessage()));
            }
            if (null === $resource) {
                continue;
            }
            // `{}` est une table vide ; `[]` aussi pour PHP, d'où le test sur la liste non vide.
            if (!\is_array($resource) || ([] !== $resource && array_is_list($resource))) {
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
     * Les manifestes du répertoire (`*.yaml`) dont un conteneur ou un
     * initContainer exécute l'image backend, triés (issue #542). Le recensement
     * lit le champ `image` parsé : guillemets et commentaire de fin de ligne
     * n'y changent rien, et une ligne commentée n'est pas une image.
     *
     * @return list<string>
     */
    public function manifestsRunningTheBackendImage(): array
    {
        $paths = glob($this->directory.'/*.yaml');
        Assert::assertIsArray($paths);

        $found = [];
        foreach ($paths as $path) {
            $manifest = basename($path);
            foreach ($this->podSpecs($manifest) as $podSpec) {
                if ([] !== self::backendContainers($podSpec) || [] !== self::backendContainers($podSpec, 'initContainers')) {
                    $found[] = $manifest;

                    break;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Vrai si la valeur d'un champ `image` désigne l'image backend.
     */
    public static function isBackendImage(mixed $image): bool
    {
        return \is_string($image) && 1 === preg_match(self::BACKEND_IMAGE, $image);
    }

    /**
     * Les conteneurs de l'image backend d'une liste du pod spec, dans l'ordre.
     *
     * @param array<mixed>                  $podSpec
     * @param 'containers'|'initContainers' $list
     *
     * @return list<array<mixed>>
     */
    public static function backendContainers(array $podSpec, string $list = 'containers'): array
    {
        return array_values(array_filter(
            self::containers($podSpec, $list),
            static fn (array $container): bool => self::isBackendImage($container['image'] ?? null),
        ));
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
     * `spec.jobTemplate.spec.template.spec` pour un CronJob. Une autre
     * ressource qui porte un `spec.template` ou un `spec.jobTemplate` (un
     * DaemonSet…) est refusée : ses pods échapperaient aux recensements.
     *
     * @param array<mixed> $resource
     *
     * @return array<mixed>|null
     */
    public static function podSpecOf(array $resource): ?array
    {
        $kind = $resource['kind'] ?? null;
        if (\in_array($kind, self::TEMPLATED_WORKLOADS, true)) {
            return self::at($resource, 'spec', 'template', 'spec');
        }
        if ('CronJob' === $kind) {
            return self::at($resource, 'spec', 'jobTemplate', 'spec', 'template', 'spec');
        }
        $spec = $resource['spec'] ?? null;
        if (\is_array($spec) && (isset($spec['template']) || isset($spec['jobTemplate']))) {
            Assert::fail(\sprintf('%s : pod spec d\'un kind que KubernetesManifests ne sait pas lire.', \is_string($kind) ? $kind : '(sans kind)'));
        }

        return null;
    }

    /**
     * Les conteneurs d'une liste du pod spec, dans l'ordre ; un élément qui
     * n'est pas une table est refusé.
     *
     * @param array<mixed>                  $podSpec
     * @param 'containers'|'initContainers' $list
     *
     * @return list<array<mixed>>
     */
    public static function containers(array $podSpec, string $list = 'containers'): array
    {
        $containers = $podSpec[$list] ?? [];
        Assert::assertIsArray($containers, \sprintf('%s : une liste est attendue.', $list));

        $tables = [];
        foreach (array_values($containers) as $index => $container) {
            if (!\is_array($container)) {
                Assert::fail(\sprintf('%s : l\'élément %d n\'est pas une table YAML, %s lu.', $list, $index + 1, get_debug_type($container)));
            }
            $tables[] = $container;
        }

        return $tables;
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
        Assert::assertCount(1, $matching, \sprintf('%s : %d %s(s) « %s » dans le %s, un seul attendu.', $manifest, \count($matching), $what, $name, $kind));

        return $matching[0];
    }

    /**
     * La ligne où commence le contenu d'un document : celle qui suit son
     * séparateur, ou la première du fichier.
     */
    private function firstLine(string $source, string $document, int $offset): int
    {
        $line = substr_count($source, "\n", 0, $offset) + 1;

        return str_starts_with($document, "\n") ? $line + 1 : $line;
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
