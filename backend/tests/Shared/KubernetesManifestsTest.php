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

    /**
     * Une profondeur de `dirname` fausse pourrait viser un autre répertoire
     * qui porte lui aussi une kustomization, un overlay par exemple.
     */
    public function testTheBaseIsTheRepositoryManifestDirectory(): void
    {
        $base = KubernetesManifests::base();

        self::assertStringEndsWith('/k8s/base', $base->directory());
        self::assertDirectoryExists($base->directory());
    }

    /**
     * Un overlay se lit tel qu'il est écrit, pas tel que `kustomize build` le
     * rend : ses propres fichiers, à côté de la base.
     */
    public function testAnOverlayIsReadBesideTheBase(): void
    {
        $overlay = KubernetesManifests::overlay('prod');

        self::assertSame(\dirname(KubernetesManifests::base()->directory()).'/overlays/prod', $overlay->directory());
        self::assertDirectoryExists($overlay->directory());
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

    /**
     * L'ancienne expression (`^---\s*$`) découpait un fichier aux fins de ligne
     * Windows ; la nouvelle aussi, séparateur commenté compris.
     */
    public function testAManifestWithWindowsLineEndingsIsSplit(): void
    {
        $this->write('crlf.yaml', "kind: A\r\n---\r\nkind: B\r\n--- # le Service\r\nkind: C\r\n");

        self::assertSame([['kind' => 'A'], ['kind' => 'B'], ['kind' => 'C']], $this->manifests()->documents('crlf.yaml'));
    }

    /**
     * Le document fautif est désigné par la ligne où il commence : un numéro
     * d'ordre compterait le morceau vide laissé par un `---` en tête.
     */
    public function testAnUnparsableDocumentIsRefused(): void
    {
        $this->write('broken.yaml', "---\nkind: A\n---\nkind: B\n  spec: [\n");

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('broken.yaml, document de la ligne 4 illisible');
        $this->manifests()->documents('broken.yaml');
    }

    /**
     * `{}` est une table, vide : rien à refuser, rien à lire.
     */
    public function testAnEmptyMappingIsAnEmptyResource(): void
    {
        $this->write('empty.yaml', "kind: A\n---\n{}\n");

        self::assertSame([['kind' => 'A'], []], $this->manifests()->documents('empty.yaml'));
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
        $this->expectExceptionMessage('odd.yaml, document de la ligne 3 :');
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
        self::assertNull(KubernetesManifests::podSpecOf(['kind' => 'Service', 'spec' => ['ports' => [['port' => 80]]]]));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unknownWorkloads(): iterable
    {
        yield 'DaemonSet' => [['kind' => 'DaemonSet', 'spec' => ['template' => ['spec' => ['containers' => []]]]]];
        yield 'jobTemplate hors CronJob' => [['kind' => 'Batch', 'spec' => ['jobTemplate' => ['spec' => []]]]];
    }

    /**
     * Une charge que le helper ne sait pas descendre ferait sauter ses pods
     * aux tests qui recensent tous les pod specs : elle est refusée.
     *
     * @param array<mixed> $resource
     */
    #[DataProvider('unknownWorkloads')]
    public function testAWorkloadOfAnUnknownKindIsRefused(array $resource): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('pod spec d\'un kind que KubernetesManifests ne sait pas lire');
        KubernetesManifests::podSpecOf($resource);
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
        $this->expectExceptionMessage('worker.yaml : 0 conteneur(s) « wait » dans le Deployment, un seul attendu.');
        $this->manifests()->container('worker.yaml', 'Deployment', 'wait');
    }

    /**
     * Un conteneur en double après un copier-coller n'est pas « absent ».
     */
    public function testADuplicatedContainerIsReportedAsSuch(): void
    {
        $this->write('twice.yaml', "kind: Deployment\nspec: {template: {spec: {containers: [{name: app}, {name: app}]}}}\n");

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('twice.yaml : 2 conteneur(s) « app » dans le Deployment, un seul attendu.');
        $this->manifests()->container('twice.yaml', 'Deployment', 'app');
    }

    public function testTheContainersOfAPodSpecListAreReadInOrder(): void
    {
        $podSpec = ['initContainers' => [['name' => 'copy']], 'containers' => [['name' => 'app'], ['name' => 'sidecar']]];

        self::assertSame([['name' => 'copy']], KubernetesManifests::containers($podSpec, 'initContainers'));
        self::assertSame([['name' => 'app'], ['name' => 'sidecar']], KubernetesManifests::containers($podSpec));
        self::assertSame([], KubernetesManifests::containers([]));
    }

    /**
     * Un élément qui n'est pas une table est refusé, pas écarté : écarté, il
     * laissait passer un `assertCount(1)` sur une liste de deux éléments.
     */
    public function testAContainerThatIsNoMappingIsRefused(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('initContainers : l\'élément 2 n\'est pas une table YAML, string lu.');
        KubernetesManifests::containers(['initContainers' => [['name' => 'copy'], 'pas une table']], 'initContainers');
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

    /**
     * Le recensement lit le champ `image` parsé, pas une ligne de texte : les
     * deux anciennes expressions divergeaient sur les guillemets et le
     * commentaire de fin de ligne (issue #542).
     */
    public function testTheCensusFindsEveryManifestRunningTheBackendImage(): void
    {
        $this->write('quoted.yaml', "kind: Deployment\nspec: {template: {spec: {containers: [{name: api, image: \"backend\"}]}}}\n");
        $this->write('commented.yaml', <<<'YAML'
            kind: Job
            spec:
              template:
                spec:
                  containers:
                    - name: migrate
                      image: backend # préchauffé
            YAML);
        $this->write('placeholder.yaml', "kind: Job\nspec: {template: {spec: {containers: [{name: seed, image: '\${BACKEND_IMAGE}'}]}}}\n");
        $this->write('tagged.yaml', "kind: CronJob\nspec: {jobTemplate: {spec: {template: {spec: {containers: [{name: cron, image: 'backend:1.2.3'}]}}}}}\n");
        $this->write('init-only.yaml', "kind: Deployment\nspec: {template: {spec: {initContainers: [{name: copy, image: backend}], containers: [{name: web, image: nginx}]}}}\n");
        $this->write('second-document.yaml', "kind: Service\n---\nkind: Deployment\nspec: {template: {spec: {containers: [{name: api, image: backend}]}}}\n");
        $this->write('other-image.yaml', <<<'YAML'
            # image: backend, en commentaire seulement
            kind: Deployment
            spec:
              template:
                spec:
                  containers:
                    - {name: web, image: frontend}
                    - {name: tools, image: backend-tools}
                    - {name: proxy, image: 'nginx:1.30 # backend'}
            YAML);
        $this->write('not-a-manifest.conf', "image: backend\n");

        self::assertSame(
            ['commented.yaml', 'init-only.yaml', 'placeholder.yaml', 'quoted.yaml', 'second-document.yaml', 'tagged.yaml'],
            $this->manifests()->manifestsRunningTheBackendImage(),
        );
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function images(): iterable
    {
        yield 'nom kustomize' => ['backend', true];
        yield 'placeholder envsubst' => ['${BACKEND_IMAGE}', true];
        // Kustomize réécrit l'image par son nom, quel que soit le tag ou le digest.
        yield 'avec tag' => ['backend:latest', true];
        yield 'avec digest' => ['backend@sha256:'.str_repeat('0', 64), true];
        yield 'autre image' => ['frontend', false];
        yield 'préfixe seulement' => ['backend-tools', false];
        yield 'registre explicite' => ['ghcr.io/ghostotof/backend:1.0', false];
        yield 'placeholder suffixé' => ['${BACKEND_IMAGE}:x', false];
        yield 'absente' => [null, false];
        yield 'pas une chaîne' => [['backend'], false];
    }

    #[DataProvider('images')]
    public function testTheBackendImageIsRecognisedByItsName(mixed $image, bool $expected): void
    {
        self::assertSame($expected, KubernetesManifests::isBackendImage($image));
    }

    public function testTheBackendContainersOfAPodSpecListAreKeptInOrder(): void
    {
        $podSpec = [
            'initContainers' => [['name' => 'copy', 'image' => 'backend'], ['name' => 'wait', 'image' => 'busybox']],
            'containers' => [['name' => 'php-fpm', 'image' => 'backend'], ['name' => 'nginx', 'image' => 'nginx'], ['name' => 'cli', 'image' => '${BACKEND_IMAGE}']],
        ];

        self::assertSame([['name' => 'copy', 'image' => 'backend']], KubernetesManifests::backendContainers($podSpec, 'initContainers'));
        self::assertSame(
            [['name' => 'php-fpm', 'image' => 'backend'], ['name' => 'cli', 'image' => '${BACKEND_IMAGE}']],
            KubernetesManifests::backendContainers($podSpec),
        );
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
