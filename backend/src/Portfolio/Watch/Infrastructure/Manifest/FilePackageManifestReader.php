<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Manifest;

use App\Portfolio\Watch\Domain\Service\PackageManifestReaderInterface;
use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Domain\ValueObject\PackageManifest;
use App\Portfolio\Watch\Infrastructure\ReadsUntrustedArrays;
use Psr\Log\LoggerInterface;

/**
 * Relit le manifeste produit au build (cf. LockFilePackageManifestBuilder).
 *
 * Un fichier illisible est traité comme un fichier absent — dans les deux cas
 * on ignore le périmètre — mais il est journalisé, lui : c'est une anomalie,
 * là où l'absence est un état normal en développement.
 */
final readonly class FilePackageManifestReader implements PackageManifestReaderInterface
{
    use ReadsUntrustedArrays;

    public function __construct(
        private string $manifestPath,
        private LoggerInterface $logger,
    ) {
    }

    public function read(): ?PackageManifest
    {
        if (!is_file($this->manifestPath) || !is_readable($this->manifestPath)) {
            return null;
        }

        $content = file_get_contents($this->manifestPath);

        if (false === $content) {
            $this->logger->warning('Manifeste de paquets illisible.', ['path' => $this->manifestPath]);

            return null;
        }

        try {
            $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning('Manifeste de paquets malformé.', [
                'path' => $this->manifestPath,
                'exception' => $exception,
            ]);

            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        return new PackageManifest($this->generatedAt($decoded), $this->packages($decoded));
    }

    /**
     * @param array<mixed> $decoded
     */
    private function generatedAt(array $decoded): \DateTimeImmutable
    {
        $value = $decoded['generatedAt'] ?? null;

        if (!\is_string($value)) {
            return new \DateTimeImmutable('@0');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return new \DateTimeImmutable('@0');
        }
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return list<PackageCoordinates>
     */
    private function packages(array $decoded): array
    {
        $packages = $decoded['packages'] ?? null;

        if (!\is_array($packages)) {
            return [];
        }

        $collected = [];

        foreach ($packages as $package) {
            if (!\is_array($package)) {
                continue;
            }

            $ecosystem = $this->readString($package, 'ecosystem');
            $name = $this->readString($package, 'name');
            $version = $this->readString($package, 'version');

            if (in_array(null, [$ecosystem, $name, $version], true)) {
                continue;
            }

            $collected[] = new PackageCoordinates($ecosystem, $name, $version);
        }

        return $collected;
    }

}
