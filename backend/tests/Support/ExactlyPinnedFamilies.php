<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * La règle des épinglages exacts des paquets 0.x de Symfony AI et du serveur
 * MCP (ADR 0004 D1, ADR 0006 D7, issue #731), jugée sur un `composer.json` et
 * un `composer.lock` décodés : le pendant d'{@see ApiPlatformLockFamily} pour
 * {@see \App\Tests\Shared\SymfonyAiAndMcpPinsMatchTheLockTest}, qui en dit les
 * raisons et en pose les cas.
 *
 * Tout paquet `symfony/ai-*`, `symfony/mcp-*` ou `mcp/*` du lock est exigé dans
 * la section que le lock impose (`packages` -> `require`, `packages-dev` ->
 * `require-dev`) par une contrainte exacte égale à sa version verrouillée,
 * sans le `v` du tag ; tout pin de ces familles absent du lock est un lock
 * désynchronisé. Le lock se lit par {@see ComposerFiles}, sans tolérance : une
 * entrée mal formée fait échouer le test ({@see MalformedLockEntry::Fails}).
 */
final class ExactlyPinnedFamilies
{
    /** La section de `composer.json` où s'épingle un paquet, selon la section du lock qui l'installe. */
    public const array REQUIRE_SECTION_OF = ['packages' => 'require', 'packages-dev' => 'require-dev'];

    /** Les familles 0.x pinnées en version exacte (ADR 0004 D1, ADR 0006 D7). */
    private const array PINNED_PREFIXES = ['symfony/ai-', 'symfony/mcp-', 'mcp/'];

    /**
     * Une version exacte, telle que la contrainte doit l'écrire : trois
     * nombres, puis au plus un suffixe de pré-version `alpha`, `beta` ou `RC`
     * (la casse que Composer admet). `dev-*`, `.x-dev` et `-dev` n'y entrent
     * pas : ce sont des branches.
     */
    private const string EXACT_VERSION = '/^\d+\.\d+\.\d+(?:-(?:alpha|beta|RC)\.?\d*)?\z/i';

    /**
     * Les paquets des familles dans le lock, `packages` et `packages-dev`
     * confondus : la section qui les installe et leur version, sans le `v`
     * des tags.
     *
     * @param array<mixed> $lock le contenu décodé de `composer.lock`
     *
     * @return array<string, array{section: 'packages'|'packages-dev', version: string}>
     */
    public static function locked(array $lock): array
    {
        $locked = [];
        foreach (ComposerFiles::lockedPackages($lock, MalformedLockEntry::Fails) as $package) {
            if (self::isPinnedFamily($package->name)) {
                $locked[$package->name] = ['section' => $package->section, 'version' => $package->versionWithoutTagPrefix()];
            }
        }

        return $locked;
    }

    /**
     * Les paquets des familles qui ne respectent pas la règle, chacun avec sa
     * raison, dans l'ordre du lock puis de `require` et `require-dev`.
     *
     * @param array<mixed> $composer le contenu décodé de `composer.json`
     * @param array<mixed> $lock     le contenu décodé de `composer.lock`
     *
     * @return array<string, string> paquet => raison
     */
    public static function violations(array $composer, array $lock): array
    {
        $locked = self::locked($lock);
        $violations = [];

        foreach ($locked as $package => ['section' => $section, 'version' => $version]) {
            $requireSection = self::REQUIRE_SECTION_OF[$section];
            $constraint = self::requirements($composer, $requireSection)[$package] ?? null;
            $expected = \sprintf('écrire "%s": "%s" dans le %s de composer.json', $package, $version, $requireSection);

            if (null === $constraint) {
                $violations[$package] = \sprintf('%s : verrouillé en %s, absent du %s — %s.', $package, $version, $requireSection, $expected);
            } elseif (!\is_string($constraint) || 1 !== preg_match(self::EXACT_VERSION, $constraint) || $constraint !== $version) {
                $found = \is_string($constraint) ? $constraint : get_debug_type($constraint);
                $violations[$package] = \sprintf('%s : verrouillé en %s, contrainte « %s » — %s.', $package, $version, $found, $expected);
            }
        }

        // Un pin des familles dont le paquet n'est pas dans le lock : lock désynchronisé.
        foreach (self::REQUIRE_SECTION_OF as $requireSection) {
            foreach (array_keys(self::requirements($composer, $requireSection)) as $package) {
                if (\is_string($package) && self::isPinnedFamily($package) && !\array_key_exists($package, $locked)) {
                    $violations[$package] = \sprintf('%s : exigé par le %s de composer.json mais absent de composer.lock — lancer composer update %s.', $package, $requireSection, $package);
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<string, string> $violations ce que {@see self::violations()} rend
     */
    public static function failure(array $violations): string
    {
        $lines = [];
        foreach ($violations as $reason) {
            $lines[] = '- '.$reason;
        }

        return "Paquets 0.x de Symfony AI ou du serveur MCP mal épinglés (ADR 0004 D1, ADR 0006 D7) :\n".implode("\n", $lines);
    }

    /**
     * @param array<mixed> $composer
     *
     * @return array<mixed>
     */
    private static function requirements(array $composer, string $section): array
    {
        return \is_array($composer[$section] ?? null) ? $composer[$section] : [];
    }

    private static function isPinnedFamily(string $package): bool
    {
        return array_any(self::PINNED_PREFIXES, static fn (string $prefix): bool => str_starts_with($package, $prefix));
    }
}
