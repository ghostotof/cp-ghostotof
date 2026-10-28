<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;

/**
 * Tous les paquets `api-platform/*` du lock sont à la même version
 * (issue #701).
 *
 * C'est la cause même de l'incident : le bundle était pinné en 4.3.19 pendant
 * que `serializer`, `state`, `metadata`… montaient en 4.4.0 par leurs
 * contraintes larges. Le bundle câblait `ItemNormalizer` pour dénormaliser,
 * les composants le dépréciaient, et `POST /api/contact` écrivait une
 * dépréciation dans les journaux de production sans qu'aucun test ne rougisse.
 * Les paquets d'API Platform se versionnent ensemble (même dépôt, mêmes
 * tags) : un lock qui les mélange est un état que personne n'a testé.
 *
 * Le test lit `composer.lock`, la seule source de ce qui s'installe — la
 * contrainte de `composer.json` ne dit rien des paquets que le bundle tire.
 */
final class ApiPlatformPackagesShareOneVersionTest extends TestCase
{
    public function testEveryApiPlatformPackageOfTheLockIsAtTheSameVersion(): void
    {
        $versions = $this->versionsByPackage();

        // Le bundle et le pont Doctrine, que `composer.json` nomme : sans eux,
        // le test ne lirait pas le bon lock.
        self::assertArrayHasKey('api-platform/symfony', $versions);
        self::assertArrayHasKey('api-platform/doctrine-orm', $versions);

        $distinct = array_unique($versions);
        self::assertCount(
            1,
            $distinct,
            "Les paquets api-platform/* du lock ne sont pas tous à la même version :\n".$this->describe($versions)
            ."\nMontez-les ensemble : composer update 'api-platform/*'.",
        );
    }

    /**
     * @return array<string, string> version par nom de paquet, dans l'ordre du lock
     */
    private function versionsByPackage(): array
    {
        $lock = json_decode(RepositoryFiles::read(RepositoryFiles::backend('composer.lock')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);

        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? [];
            self::assertIsArray($packages);
            foreach ($packages as $package) {
                self::assertIsArray($package);
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                if (\is_string($name) && \is_string($version) && str_starts_with($name, 'api-platform/')) {
                    $versions[$name] = $version;
                }
            }
        }

        return $versions;
    }

    /**
     * @param array<string, string> $versions
     */
    private function describe(array $versions): string
    {
        $lines = [];
        foreach ($versions as $name => $version) {
            $lines[] = \sprintf(' - %s %s', $name, $version);
        }

        return implode("\n", $lines);
    }
}
