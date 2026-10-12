<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Ce que {@see KubernetesManifests} lit, sur des manifestes écrits pour
 * l'occasion (issue #541) : le découpage multi-documents, la descente jusqu'au
 * pod spec de chaque sorte de charge, et le refus d'un document illisible.
 * Les tests de `k8s/base` passent tous par lui : un format qu'il lirait mal
 * les rendrait verts à tort, tous ensemble.
 */
final class KubernetesManifestsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/kubernetes-manifests-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheBaseIsTheRepositoryManifestDirectory(): void
    {
        self::assertSame('Kustomization', KubernetesManifests::base()->resource('kustomization.yaml', 'Kustomization')['kind'] ?? null);
    }

    /**
     * Un séparateur en tête de fichier ou en fin de fichier laisse un document
     * vide, qui n'en est pas un.
     */
    public function testEveryDocumentIsReadAndEmptyOnesAreSkipped(): void
    {
        $this->write('multi.yaml', "---\nkind: A\n---\n# rien\n---\nkind: B\n---\n");

        self::assertSame([['kind' => 'A'], ['kind' => 'B']], $this->manifests()->documents('multi.yaml'));
    }

    /**
     * `--- # commentaire` sépare deux documents en YAML ; l'ancienne
     * expression des tests (`^---\s*$`) ne le découpait pas.
     */
    public function testACommentedSeparatorSplitsTheDocuments(): void
    {
        $this->write('commented.yaml', "kind: A\n--- # le Deployment\nkind: B\n");

        self::assertSame([['kind' => 'A'], ['kind' => 'B']], $this->manifests()->documents('commented.yaml'));
    }

    public function testAnUnparsableDocumentIsRefused(): void
    {
        $this->write('broken.yaml', "kind: A\n---\nkind: B\n  spec: [\n");

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('broken.yaml, document 2');
        $this->manifests()->documents('broken.yaml');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsThatAreNoResource(): iterable
    {
        yield 'scalaire' => ["kind: A\n---\njuste du texte\n"];
        yield 'liste' => ["kind: A\n---\n- kind: B\n"];
    }

    /**
     * Les anciennes copies passaient un tel document sous silence : un
     * Deployment mal indenté devenait une chaîne, et le test restait vert.
     */
    #[DataProvider('documentsThatAreNoResource')]
    public function testADocumentThatIsNoMappingIsRefused(string $yaml): void
    {
        $this->write('odd.yaml', $yaml);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('odd.yaml, document 2');
        $this->manifests()->documents('odd.yaml');
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function workloads(): iterable
    {
        $podSpec = ['containers' => [['name' => 'app']]];

        yield 'Deployment' => [['kind' => 'Deployment', 'spec' => ['template' => ['spec' => $podSpec]]]];
        yield 'StatefulSet' => [['kind' => 'StatefulSet', 'spec' => ['template' => ['spec' => $podSpec]]]];
        yield 'Job' => [['kind' => 'Job', 'spec' => ['template' => ['spec' => $podSpec]]]];
        yield 'CronJob' => [['kind' => 'CronJob', 'spec' => ['jobTemplate' => ['spec' => ['template' => ['spec' => $podSpec]]]]]];
    }

    /**
     * @param array<mixed> $resource
     */
    #[DataProvider('workloads')]
    public function testThePodSpecOfEveryWorkloadKindIsReached(array $resource): void
    {
        self::assertSame(['containers' => [['name' => 'app']]], KubernetesManifests::podSpecOf($resource));
    }

    public function testAResourceWithoutPodsHasNoPodSpec(): void
    {
        self::assertNull(KubernetesManifests::podSpecOf(['kind' => 'Service', 'spec' => ['template' => ['spec' => []]]]));
    }

    public function testAContainerIsFoundByNameInTheDocumentOfItsKind(): void
    {
        $this->write('broker.yaml', <<<'YAML'
            kind: PersistentVolumeClaim
            metadata: {name: data}
            --- # le broker
            kind: Deployment
            spec:
              template:
                spec:
                  initContainers:
                    - {name: wait, image: busybox}
                  containers:
                    - {name: sidecar, image: exporter}
                    - {name: broker, image: rabbitmq}
            ---
            kind: Service
            metadata: {name: broker}
            YAML);
        $manifests = $this->manifests();

        self::assertSame(['name' => 'broker', 'image' => 'rabbitmq'], $manifests->container('broker.yaml', 'Deployment', 'broker'));
        self::assertSame(['name' => 'wait', 'image' => 'busybox'], $manifests->initContainer('broker.yaml', 'Deployment', 'wait'));
    }

    /**
     * Un conteneur d'une liste n'est pas cherché dans l'autre : le
     * `wait-for-rabbitmq` du worker est un initContainer, pas un conteneur.
     */
    public function testAContainerIsNotSoughtInTheInitContainers(): void
    {
        $this->write('worker.yaml', "kind: Deployment\nspec: {template: {spec: {initContainers: [{name: wait}], containers: [{name: worker}]}}}\n");

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('worker.yaml : conteneur « wait » absent du Deployment.');
        $this->manifests()->container('worker.yaml', 'Deployment', 'wait');
    }

    public function testTheContainersOfAPodSpecListAreTheTablesOfThatList(): void
    {
        $podSpec = ['initContainers' => [['name' => 'copy'], 'pas une table'], 'containers' => [['name' => 'app']]];

        self::assertSame([['name' => 'copy']], KubernetesManifests::containers($podSpec, 'initContainers'));
        self::assertSame([['name' => 'app']], KubernetesManifests::containers($podSpec));
        self::assertSame([], KubernetesManifests::containers([]));
    }

    public function testThePodSpecsOfEveryWorkloadOfAManifestAreRead(): void
    {
        $this->write('two.yaml', <<<'YAML'
            kind: Deployment
            spec: {template: {spec: {containers: [{name: api}]}}}
            ---
            kind: Service
            ---
            kind: CronJob
            spec: {jobTemplate: {spec: {template: {spec: {containers: [{name: cron}]}}}}}
            YAML);

        self::assertSame(
            [['containers' => [['name' => 'api']]], ['containers' => [['name' => 'cron']]]],
            $this->manifests()->podSpecs('two.yaml'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ambiguousResources(): iterable
    {
        yield 'absent' => ["kind: Service\n", 'absent.yaml : 0 document(s) de kind Deployment, un seul attendu.'];
        yield 'en double' => ["kind: Deployment\n---\nkind: Deployment\n", 'absent.yaml : 2 document(s) de kind Deployment, un seul attendu.'];
    }

    #[DataProvider('ambiguousResources')]
    public function testAResourceIsTheOnlyDocumentOfItsKind(string $yaml, string $message): void
    {
        $this->write('absent.yaml', $yaml);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($message);
        $this->manifests()->resource('absent.yaml', 'Deployment');
    }

    public function testAResourceWithoutPodSpecFailsWhenOneIsRequired(): void
    {
        $this->write('service.yaml', "kind: Deployment\nspec: {}\n");

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('service.yaml : le Deployment n\'a pas de pod spec.');
        $this->manifests()->podSpec('service.yaml', 'Deployment');
    }

    private function manifests(): KubernetesManifests
    {
        return KubernetesManifests::in($this->directory);
    }

    private function write(string $manifest, string $yaml): void
    {
        new Filesystem()->dumpFile($this->directory.'/'.$manifest, $yaml);
    }
}
