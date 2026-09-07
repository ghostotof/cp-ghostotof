<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Manifest;

use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Infrastructure\Manifest\LockFilePackageManifestBuilder;
use PHPUnit\Framework\TestCase;

final class LockFilePackageManifestBuilderTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().'/watch-manifest-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $this->workingDirectory = $directory;
    }

    protected function tearDown(): void
    {
        $files = glob($this->workingDirectory.'/*');

        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }

        rmdir($this->workingDirectory);
    }

    private function path(string $name): string
    {
        return $this->workingDirectory.'/'.$name;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function writeLock(string $name, array $content): string
    {
        file_put_contents($this->path($name), json_encode($content, \JSON_THROW_ON_ERROR));

        return $this->path($name);
    }

    private function builder(?string $composerLock, ?string $npmLock): LockFilePackageManifestBuilder
    {
        return new LockFilePackageManifestBuilder(
            $composerLock ?? $this->path('absent-composer.lock'),
            $npmLock ?? $this->path('absent-package-lock.json'),
            $this->path('package-manifest.json'),
        );
    }

    private function composerLock(): string
    {
        return $this->writeLock('composer.lock', [
            'packages' => [
                ['name' => 'symfony/http-client', 'version' => '8.1.4'],
                // Les tags Composer portent souvent un « v » que Packagist ne
                // connaît pas dans ses numéros de version.
                ['name' => 'api-platform/core', 'version' => 'v4.3.17'],
            ],
            'packages-dev' => [
                ['name' => 'phpunit/phpunit', 'version' => '13.3.0'],
            ],
        ]);
    }

    private function npmLock(): string
    {
        return $this->writeLock('package-lock.json', [
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'app', 'version' => '0.0.0'],
                'node_modules/vue' => ['version' => '3.5.42'],
                'node_modules/vitest' => ['version' => '4.1.11', 'dev' => true],
                // Dépendance imbriquée : le nom est ce qui suit le dernier
                // « node_modules/ », pas le chemin complet.
                'node_modules/a/node_modules/b' => ['version' => '1.0.0'],
            ],
        ]);
    }

    /**
     * @return array<string, string> nom du paquet => écosystème
     */
    private function ecosystemsByName(LockFilePackageManifestBuilder $builder): array
    {
        $manifest = $builder->build(new \DateTimeImmutable('2026-09-07 12:00:00'));

        $byName = [];
        foreach ($manifest->packages as $package) {
            $byName[$package->name] = $package->ecosystem;
        }

        return $byName;
    }

    public function testItCollectsBothEcosystems(): void
    {
        $packages = $this->ecosystemsByName($this->builder($this->composerLock(), $this->npmLock()));

        self::assertSame(PackageCoordinates::ECOSYSTEM_PACKAGIST, $packages['symfony/http-client']);
        self::assertSame(PackageCoordinates::ECOSYSTEM_NPM, $packages['vue']);
    }

    /**
     * L'image de production est construite avec `composer install --no-dev` et
     * un bundle frontend qui n'embarque pas les outils de développement : une
     * vulnérabilité dans PHPUnit ou Vitest ne s'exécute jamais en production.
     * L'audit des dépendances de build reste l'affaire de la CI, qui le fait
     * déjà de façon bloquante.
     */
    public function testItIgnoresDevelopmentDependencies(): void
    {
        $packages = $this->ecosystemsByName($this->builder($this->composerLock(), $this->npmLock()));

        self::assertArrayNotHasKey('phpunit/phpunit', $packages);
        self::assertArrayNotHasKey('vitest', $packages);
    }

    public function testItNormalizesComposerVersionTags(): void
    {
        $manifest = $this->builder($this->composerLock(), null)->build(new \DateTimeImmutable());

        $versions = [];
        foreach ($manifest->packages as $package) {
            $versions[$package->name] = $package->version;
        }

        self::assertSame('4.3.17', $versions['api-platform/core']);
        self::assertSame('8.1.4', $versions['symfony/http-client']);
    }

    public function testItNamesNestedNpmPackagesByTheirOwnName(): void
    {
        $packages = $this->ecosystemsByName($this->builder(null, $this->npmLock()));

        self::assertArrayHasKey('b', $packages);
        self::assertArrayNotHasKey('node_modules/a/node_modules/b', $packages);
    }

    /**
     * Le conteneur backend ne voit pas le dépôt frontend : en développement, la
     * commande ne dispose que de composer.lock. Ce n'est pas une erreur, c'est
     * un périmètre réduit — et il vaut mieux un manifeste partiel qu'aucun.
     */
    public function testAMissingNpmLockYieldsABackendOnlyManifest(): void
    {
        $packages = $this->ecosystemsByName($this->builder($this->composerLock(), null));

        self::assertArrayHasKey('symfony/http-client', $packages);
        self::assertNotContains(PackageCoordinates::ECOSYSTEM_NPM, $packages);
    }

    public function testItWritesTheManifestWhereItWillBeRead(): void
    {
        $this->builder($this->composerLock(), $this->npmLock())->build(new \DateTimeImmutable());

        self::assertFileExists($this->path('package-manifest.json'));

        $written = json_decode((string) file_get_contents($this->path('package-manifest.json')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($written);
        self::assertArrayHasKey('generatedAt', $written);
        self::assertArrayHasKey('packages', $written);
    }

    /**
     * Aucun lock lisible : le manifeste produit est vide plutôt qu'absent. La
     * nuance compte — un manifeste vide dit « rien à analyser », son absence
     * dirait « analyse jamais tentée », et la page ne doit pas confondre les
     * deux.
     */
    public function testWithoutAnyLockTheManifestIsEmptyButReal(): void
    {
        $manifest = $this->builder(null, null)->build(new \DateTimeImmutable());

        self::assertSame([], $manifest->packages);
        self::assertFileExists($this->path('package-manifest.json'));
    }
}
