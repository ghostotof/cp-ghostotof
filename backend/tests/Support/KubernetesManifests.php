<?php

declare(strict_types=1);

namespace App\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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
     * L'image backend sous tous ses noms : `backend`, que l'`images:` de la
     * kustomization réécrit — par son nom, tag ou digest compris —, l'image
     * que la pipeline publie (`IMAGE_BACKEND`, pipeline.yml) écrite en dur, et
     * le placeholder des Jobs appliqués hors kustomize, que `envsubst`
     * remplace avec ou sans accolades.
     */
    private const string BACKEND_IMAGE = '/\A(?:(?:(?:ghcr\.io\/ghostotof\/cp-ghostotof-)?backend)(?:[:@][^\s\/]+)?|\$\{BACKEND_IMAGE\}|\$BACKEND_IMAGE)\z/';

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
     * Tout `k8s/` : la base, les overlays et les fichiers posés à sa racine.
     * Pour {@see self::linesMatching()}, qui descend dans les
     * sous-répertoires ; les lectures par manifeste restent à `base()` et
     * `overlay()`.
     */
    public static function tree(): self
    {
        return new self(\dirname(self::base()->directory()));
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
     * Les lignes qui répondent à `$pattern`, dans chaque fichier du
     * répertoire et de ses sous-répertoires, documentation (`*.md`)
     * exceptée, chacune préfixée de son chemin relatif et de son numéro,
     * triées (issue #616). Une lecture du texte, pour un garde qui refuse un
     * mot où qu'il soit écrit : quelle que soit l'extension — un `.env` de
     * `configMapGenerator` nourrit l'environnement d'un pod —, commentaires
     * compris, puisqu'un `#` dans un scalaire de bloc YAML n'en ouvre pas
     * forcément un. Ce qui se lit par ressource passe par {@see self::documents()}.
     *
     * @return list<string>
     */
    public function linesMatching(string $pattern): array
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS));
        $matches = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || 'md' === $file->getExtension()) {
                continue;
            }
            $lines = file($file->getPathname(), \FILE_IGNORE_NEW_LINES);
            Assert::assertIsArray($lines, \sprintf('%s illisible.', $file->getPathname()));
            foreach ($lines as $index => $line) {
                if (1 === preg_match($pattern, $line)) {
                    $matches[] = \sprintf('%s:%d: %s', substr($file->getPathname(), \strlen($this->directory) + 1), $index + 1, trim($line));
                }
            }
        }
        sort($matches);

        return $matches;
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
     * Les manifestes du répertoire (`*.yaml`, `*.yml`) dont un conteneur ou un
     * initContainer exécute l'image backend, triés (issue #542). Le recensement
     * lit le champ `image` parsé : guillemets et commentaire de fin de ligne
     * n'y changent rien, et une ligne commentée n'est pas une image.
     *
     * @return list<string>
     */
    public function manifestsRunningTheBackendImage(): array
    {
        // Deux motifs plutôt que GLOB_BRACE, absent de certaines libc (musl).
        $yaml = glob($this->directory.'/*.yaml');
        $yml = glob($this->directory.'/*.yml');
        Assert::assertIsArray($yaml);
        Assert::assertIsArray($yml);

        $found = [];
        foreach ([...$yaml, ...$yml] as $path) {
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
     * Les conteneurs de l'image backend d'une liste, dans tous les pod specs
     * du manifeste, dans l'ordre du fichier.
     *
     * @param 'containers'|'initContainers' $list
     *
     * @return list<array<mixed>>
     */
    public function backendContainersIn(string $manifest, string $list = 'containers'): array
    {
        $containers = [];
        foreach ($this->podSpecs($manifest) as $podSpec) {
            array_push($containers, ...self::backendContainers($podSpec, $list));
        }

        return $containers;
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
     * `spec.jobTemplate.spec.template.spec` pour un CronJob, `spec` pour un
     * Pod nu. Une autre ressource qui porte un `spec.template`, un
     * `spec.jobTemplate` ou une liste `containers` à n'importe quelle
     * profondeur (un DaemonSet, un PodTemplate, une `List` de Pods…) est
     * refusée : ses pods échapperaient aux recensements.
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
        if ('Pod' === $kind) {
            return self::at($resource, 'spec');
        }
        $spec = $resource['spec'] ?? null;
        if ((\is_array($spec) && (isset($spec['template']) || isset($spec['jobTemplate']))) || self::holdsContainers($resource)) {
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
        return self::entries($podSpec, $list);
    }

    /**
     * Les éléments d'une liste YAML de ce nœud (`volumes`, `volumeMounts`,
     * `env`…), dans l'ordre ; une liste absente est vide. Une valeur qui n'est
     * pas une liste, ou un élément qui n'est pas une table, est refusé : écarté,
     * il ferait passer une entrée mal écrite pour absente.
     *
     * @param array<mixed> $node
     *
     * @return list<array<mixed>>
     */
    public static function entries(array $node, string $list): array
    {
        $entries = $node[$list] ?? [];
        Assert::assertIsArray($entries, \sprintf('%s : une liste est attendue.', $list));
        // Une table de tables passerait l'assertion précédente.
        Assert::assertTrue(array_is_list($entries), \sprintf('%s : une liste est attendue.', $list));

        $tables = [];
        foreach ($entries as $index => $entry) {
            if (!\is_array($entry)) {
                Assert::fail(\sprintf('%s : l\'élément %d n\'est pas une table YAML, %s lu.', $list, $index + 1, get_debug_type($entry)));
            }
            $tables[] = $entry;
        }

        return $tables;
    }

    /**
     * Le premier élément d'une liste du nœud dont `$key` vaut `$value`, ou
     * null : le volume `cache-system` d'un pod spec, le montage d'un chemin
     * dans un conteneur, une variable de son `env`.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>|null
     */
    public static function firstWith(array $node, string $list, string $key, string $value): ?array
    {
        foreach (self::entries($node, $list) as $entry) {
            if ($value === ($entry[$key] ?? null)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Une valeur YAML rendue lisible : chaîne telle quelle, liste jointe par
     * des espaces (une commande `['sh', '-c', '…']`, ses éléments non textuels
     * écartés), rien sinon.
     */
    public static function text(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_array($value) ? implode(' ', array_filter($value, \is_string(...))) : '';
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
     * Vrai si le nœud porte, à une profondeur quelconque, une clé
     * `containers` dont la valeur est une liste ou une table.
     *
     * @param array<mixed> $node
     */
    private static function holdsContainers(array $node): bool
    {
        foreach ($node as $key => $child) {
            if (!\is_array($child)) {
                continue;
            }
            if ('containers' === $key || self::holdsContainers($child)) {
                return true;
            }
        }

        return false;
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
