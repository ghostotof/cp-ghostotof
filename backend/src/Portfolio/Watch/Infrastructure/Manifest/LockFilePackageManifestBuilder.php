<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Manifest;

use App\Portfolio\Watch\Domain\Service\PackageManifestBuilderInterface;
use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Domain\ValueObject\PackageManifest;
use App\Portfolio\Watch\Infrastructure\ReadsUntrustedArrays;

/**
 * Produit le manifeste des paquets déployés à partir des fichiers de
 * verrouillage, et l'écrit là où l'application le relira.
 *
 * **Seules les dépendances de production sont retenues.** L'image est
 * construite avec `composer install --no-dev`, et le bundle frontend n'embarque
 * pas les outils de développement : une faille dans PHPUnit ou Vitest ne
 * s'exécute jamais en production. L'audit des dépendances de build reste
 * l'affaire de la CI, qui le fait déjà de façon bloquante.
 *
 * Chaque fichier de verrouillage est facultatif. Le conteneur backend ne voit
 * pas le dépôt frontend : en développement, la commande ne dispose que de
 * composer.lock, et un manifeste partiel vaut mieux que pas de manifeste.
 */
final readonly class LockFilePackageManifestBuilder implements PackageManifestBuilderInterface
{
    use ReadsUntrustedArrays;

    public function __construct(
        private string $composerLockPath,
        private string $npmLockPath,
        private string $manifestPath,
    ) {
    }

    public function build(\DateTimeImmutable $generatedAt): PackageManifest
    {
        $manifest = new PackageManifest($generatedAt, [
            ...$this->composerPackages(),
            ...$this->npmPackages(),
        ]);

        $this->write($manifest);

        return $manifest;
    }

    /**
     * @return list<PackageCoordinates>
     */
    private function composerPackages(): array
    {
        $lock = $this->decode($this->composerLockPath);
        $packages = $lock['packages'] ?? null;

        if (!\is_array($packages)) {
            return [];
        }

        $collected = [];

        foreach ($packages as $package) {
            if (!\is_array($package)) {
                continue;
            }

            $name = $this->readString($package, 'name');
            $version = $this->readString($package, 'version');

            if (null === $name || null === $version) {
                continue;
            }

            $collected[] = new PackageCoordinates(
                PackageCoordinates::ECOSYSTEM_PACKAGIST,
                $name,
                // Les tags Composer portent souvent un « v » que les numéros de
                // version publiés sur Packagist n'ont pas.
                ltrim($version, 'v'),
            );
        }

        return $collected;
    }

    /**
     * @return list<PackageCoordinates>
     */
    private function npmPackages(): array
    {
        $lock = $this->decode($this->npmLockPath);
        $packages = $lock['packages'] ?? null;

        if (!\is_array($packages)) {
            return [];
        }

        $collected = [];

        foreach ($packages as $path => $package) {
            // L'entrée de clé vide est le projet lui-même, pas une dépendance.
            if ('' === $path || !\is_string($path) || !\is_array($package)) {
                continue;
            }

            if (true === ($package['dev'] ?? null)) {
                continue;
            }

            $version = $this->readString($package, 'version');

            if (null === $version) {
                continue;
            }

            $collected[] = new PackageCoordinates(
                PackageCoordinates::ECOSYSTEM_NPM,
                $this->npmNameFromPath($path),
                $version,
            );
        }

        return $collected;
    }

    /**
     * Une dépendance imbriquée s'écrit `node_modules/a/node_modules/b` : son
     * nom est ce qui suit le dernier `node_modules/`, et les paquets à portée
     * (`@scope/nom`) doivent survivre au découpage.
     */
    private function npmNameFromPath(string $path): string
    {
        $position = strrpos($path, 'node_modules/');

        return false === $position ? $path : substr($path, $position + \strlen('node_modules/'));
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if (false === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }


    private function write(PackageManifest $manifest): void
    {
        $directory = \dirname($this->manifestPath);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Impossible de créer le répertoire "%s".', $directory));
        }

        $payload = [
            'generatedAt' => $manifest->generatedAt->format(\DATE_ATOM),
            'packages' => array_map(
                static fn (PackageCoordinates $package): array => [
                    'ecosystem' => $package->ecosystem,
                    'name' => $package->name,
                    'version' => $package->version,
                ],
                $manifest->packages,
            ),
        ];

        file_put_contents(
            $this->manifestPath,
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }
}
