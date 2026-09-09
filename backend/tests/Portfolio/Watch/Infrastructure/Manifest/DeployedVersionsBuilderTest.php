<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Manifest;

use App\Portfolio\Watch\Infrastructure\Manifest\DeployedVersionsBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Le relevé s'appuie sur des fichiers du dépôt. Ces tests le confrontent à des
 * copies réduites plutôt qu'aux vrais fichiers : un test qui lirait
 * `k8s/base/postgres.yaml` échouerait à la prochaine montée de version, pour
 * une raison qui n'aurait rien à voir avec ce qu'il vérifie.
 */
final class DeployedVersionsBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/versions-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/k8s/base', 0o775, true);
    }

    protected function tearDown(): void
    {
        $paths = glob($this->root.'/**/*');

        foreach (false === $paths ? [] : $paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function givenManifest(string $name, string $image): void
    {
        file_put_contents(
            $this->root.'/k8s/base/'.$name,
            "spec:\n  containers:\n    - name: x\n      image: {$image}\n",
        );
    }

    /**
     * @param array<string, string> $tagOverrides
     *
     * @return array<string, string>
     */
    private function build(array $tagOverrides = []): array
    {
        return (new DeployedVersionsBuilder(
            $this->root,
            $this->root.'/package-lock.json',
            $this->root.'/out.json',
            $tagOverrides,
        ))->build(new \DateTimeImmutable('2026-09-09T12:00:00+00:00'));
    }

    /**
     * Le cas nominal, et le vrai sujet de cette classe : un tag d'image porte
     * sa variante de distribution, dont le lecteur n'a que faire.
     */
    public function testItKeepsOnlyTheVersionFromAnImageTag(): void
    {
        $this->givenManifest('postgres.yaml', 'postgres:18.4-alpine');
        $this->givenManifest('rabbitmq.yaml', 'rabbitmq:4.3.4-management-alpine');
        $this->givenManifest('backend-deployment.yaml', 'nginx:1.30.4-alpine');

        $versions = $this->build();

        self::assertSame('18.4', $versions['postgresql']);
        self::assertSame('4.3.4', $versions['rabbitmq']);
        self::assertSame('1.30.4', $versions['nginx']);
    }

    public function testItReadsTheNodeVersionFromTheEnvFile(): void
    {
        file_put_contents($this->root.'/.env', "UID=1000\nNODE_TAG=26.7.0-alpine3.24\nHTTP_PORT=8080\n");

        self::assertSame('26.7.0', $this->build()['nodejs']);
    }

    public function testItReadsTheVueVersionFromTheNpmLockFile(): void
    {
        file_put_contents(
            $this->root.'/package-lock.json',
            json_encode(['packages' => ['node_modules/vue' => ['version' => '3.5.42']]]),
        );

        self::assertSame('3.5.42', $this->build()['vue']);
    }

    /**
     * Un fichier renommé ou déplacé ne doit pas faire échouer la construction de
     * l'image : le produit reste sans version, ce que la page sait afficher.
     * Faire tomber un build pour cela serait hors de proportion.
     */
    public function testAMissingSourceIsSkippedRatherThanFatal(): void
    {
        $this->givenManifest('postgres.yaml', 'postgres:18.4-alpine');

        $versions = $this->build();

        self::assertSame(['postgresql' => '18.4'], $versions);
    }

    /**
     * Un tag sans numéro — `latest`, typiquement — ne donne aucune version
     * exploitable. Mieux vaut ne rien annoncer que d'afficher « latest », qui
     * ne dit pas ce qui tourne.
     */
    public function testATagWithoutANumberYieldsNoVersion(): void
    {
        $this->givenManifest('postgres.yaml', 'postgres:latest');

        self::assertArrayNotHasKey('postgresql', $this->build());
    }

    public function testItWritesTheFileItAnnounces(): void
    {
        $this->givenManifest('postgres.yaml', 'postgres:18.4-alpine');

        $this->build();

        $written = json_decode((string) file_get_contents($this->root.'/out.json'), true);

        self::assertIsArray($written);
        self::assertSame('2026-09-09T12:00:00+00:00', $written['generatedAt']);
        self::assertSame(['postgresql' => '18.4'], $written['versions']);
    }

    /**
     * Au `docker build`, les tags arrivent en arguments et non par copie des
     * fichiers sources : `k8s/` est exclu du contexte — l'image n'a pas à
     * embarquer les manifestes — et copier `.env` créerait une couche qui le
     * conserverait même supprimé ensuite.
     *
     * Ce test vaut donc pour les quatre : la surcharge doit primer même quand
     * le fichier existe et dit autre chose.
     */
    public function testABuildArgumentWinsOverTheFileItWouldHaveRead(): void
    {
        file_put_contents($this->root.'/.env', "NODE_TAG=20.0.0-alpine\n");
        $this->givenManifest('postgres.yaml', 'postgres:1.0-alpine');

        $versions = $this->build(['nodejs' => '26.7.0-alpine3.24', 'postgresql' => '18.4-alpine']);

        self::assertSame('26.7.0', $versions['nodejs']);
        self::assertSame('18.4', $versions['postgresql']);
    }

    /**
     * Sans surcharge, on retombe sur les fichiers : c'est la voie du
     * développement, où la racine du dépôt est montée en lecture seule.
     */
    public function testTheFilesAreReadWhenNoArgumentIsGiven(): void
    {
        file_put_contents($this->root.'/.env', "NODE_TAG=20.0.0-alpine\n");

        self::assertSame('20.0.0', $this->build()['nodejs']);
    }
}
