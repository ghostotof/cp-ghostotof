<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * La lecture de `composer.json` et `composer.lock` par les gardes de
 * dépendances (#731), écrite une fois : le décodage JSON des fichiers du
 * backend par {@see RepositoryFiles}, et le parcours des paquets du lock,
 * `packages` puis `packages-dev`.
 *
 * Utilisée par {@see ApiPlatformLockFamily} (et
 * {@see \App\Tests\Shared\Infrastructure\ApiPlatform\ApiPlatformPackagesShareOneVersionTest}),
 * {@see ExactlyPinnedFamilies} (et
 * {@see \App\Tests\Shared\SymfonyAiAndMcpPinsMatchTheLockTest}) et
 * {@see \App\Tests\Mcp\Presentation\McpEndpointTest}. Elle ne normalise rien
 * d'office : la version reste celle du lock, `v` compris (le garde d'API
 * Platform la cite ainsi), et {@see LockedPackage::versionWithoutTagPrefix()}
 * la donne comme `composer.json` l'écrit. La tolérance aux entrées mal formées
 * est celle de l'appelant ({@see MalformedLockEntry}).
 */
final class ComposerFiles
{
    /** Les sections du lock, dans l'ordre où Composer les écrit. */
    private const array LOCK_SECTIONS = ['packages', 'packages-dev'];

    /**
     * Le contenu décodé d'un fichier JSON du backend (`composer.json`,
     * `composer.lock`) ; le test échoue s'il n'est pas un objet JSON.
     *
     * @return array<mixed>
     */
    public static function decoded(string $file): array
    {
        $decoded = json_decode(RepositoryFiles::read(RepositoryFiles::backend($file)), true, flags: \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded, \sprintf('backend/%s n\'est pas un objet JSON.', $file));

        return $decoded;
    }

    /**
     * Les paquets du lock, dans son ordre. Une section absente est vide ; une
     * entrée mal formée est ignorée ou fait échouer le test, selon
     * `$malformed`. Un `require` qui n'est pas un objet se lit vide dans les
     * deux cas.
     *
     * @param array<mixed> $lock le contenu décodé de `composer.lock`
     *
     * @return list<LockedPackage>
     */
    public static function lockedPackages(array $lock, MalformedLockEntry $malformed): array
    {
        $packages = [];
        foreach (self::LOCK_SECTIONS as $section) {
            $entries = $lock[$section] ?? [];
            if (!\is_array($entries)) {
                self::malformed($malformed, \sprintf('la section « %s » de composer.lock n\'est pas une liste', $section));

                continue;
            }

            foreach ($entries as $entry) {
                $package = self::package($section, $entry);
                if (null === $package) {
                    self::malformed($malformed, \sprintf('une entrée de « %s » sans nom ni version lisibles : %s', $section, get_debug_type($entry)));

                    continue;
                }

                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * @param 'packages'|'packages-dev' $section
     */
    private static function package(string $section, mixed $entry): ?LockedPackage
    {
        if (!\is_array($entry) || !\is_string($entry['name'] ?? null) || !\is_string($entry['version'] ?? null)) {
            return null;
        }

        $require = \is_array($entry['require'] ?? null) ? array_keys($entry['require']) : [];

        return new LockedPackage($section, $entry['name'], $entry['version'], array_values(array_filter($require, \is_string(...))));
    }

    private static function malformed(MalformedLockEntry $malformed, string $reason): void
    {
        if (MalformedLockEntry::Fails === $malformed) {
            Assert::fail('composer.lock illisible : '.$reason.'.');
        }
    }
}
