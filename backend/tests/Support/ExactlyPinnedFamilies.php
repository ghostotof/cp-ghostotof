<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * La règle des épinglages exacts des paquets 0.x de Symfony AI et du serveur
 * MCP (ADR 0004 D1, ADR 0006 D7, issue #731), jugée sur un `composer.json` et
 * un `composer.lock` décodés : le pendant d'{@see \App\Tests\Support\ApiPlatformLockFamily}
 * pour {@see \App\Tests\Shared\SymfonyAiAndMcpPinsMatchTheLockTest}, qui en dit
 * les raisons et en pose les cas.
 *
 * Tout paquet `symfony/ai-*`, `symfony/mcp-*` ou `mcp/*` du lock est exigé dans
 * la section que le lock impose (`packages` -> `require`, `packages-dev` ->
 * `require-dev`) par une version seule, sans `v` ni opérateur, que Composer
 * ({@see VersionParser}) lit comme sa version verrouillée ; tout pin de ces
 * familles absent du lock est un lock désynchronisé. Un pin écrit dans
 * l'autre section que celle où le lock installe le paquet l'est aussi quand
 * Composer n'a pas pu l'y placer — un `require` en `packages-dev`, un
 * `require-dev` en `packages` qu'aucun paquet de production n'exige — : le
 * message dit alors de lancer `composer update --lock`, pas de déplacer le
 * pin. Le lock se lit par {@see ComposerFiles}, sans tolérance : une entrée
 * mal formée fait échouer le test ({@see MalformedLockEntry::Fails}).
 */
final class ExactlyPinnedFamilies
{
    /** La section de `composer.json` où s'épingle un paquet, selon la section du lock qui l'installe. */
    public const array REQUIRE_SECTION_OF = ['packages' => 'require', 'packages-dev' => 'require-dev'];

    /** Les familles 0.x pinnées en version exacte (ADR 0004 D1, ADR 0006 D7). */
    private const array PINNED_PREFIXES = ['symfony/ai-', 'symfony/mcp-', 'mcp/'];

    /**
     * La forme d'une version seule, avant que Composer la lise : un chiffre en
     * tête, puis des chiffres, des lettres, des points et des tirets. Elle
     * écarte ce que {@see VersionParser::normalize()} accepterait et
     * effacerait en silence — le `v` d'un tag, les espaces autour, un alias
     * `as`, un drapeau `@stabilité` — et tout opérateur, joker ou alternative
     * (`^`, `~`, `>`, `=`, `*`, `|`, `,`, espace). Le sens de la version (sa
     * pré-version, sa stabilité, son égalité au lock) est laissé à Composer.
     */
    private const string SINGLE_VERSION_SHAPE = '/^\d[0-9A-Za-z.\-]*\z/';

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
        $requiredInProduction = self::requiredInProduction($lock);
        $violations = [];

        foreach ($locked as $package => ['section' => $section, 'version' => $version]) {
            $requireSection = self::REQUIRE_SECTION_OF[$section];
            $otherSection = 'require' === $requireSection ? 'require-dev' : 'require';
            $constraint = self::requirements($composer, $requireSection)[$package] ?? null;
            $expected = \sprintf('écrire "%s": "%s" dans le %s de composer.json', $package, $version, $requireSection);

            if (null === $constraint && self::lockLagsBehind($composer, $package, $otherSection, $requiredInProduction)) {
                $violations[$package] = \sprintf('%s : verrouillé en %s dans les %s de composer.lock, mais exigé par le %s de composer.json : lock désynchronisé — lancer composer update --lock, sans déplacer le pin.', $package, $version, $section, $otherSection);
            } elseif (null === $constraint) {
                $violations[$package] = \sprintf('%s : verrouillé en %s, absent du %s — %s.', $package, $version, $requireSection, $expected);
            } elseif (!\is_string($constraint) || !self::pinsExactly($constraint, $version)) {
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

    /**
     * Le paquet est exigé par l'autre section de `composer.json` que celle où
     * le lock l'installe, et c'est le lock qui retarde : un `require` racine
     * est toujours installé en `packages`, donc jamais en `packages-dev` ; un
     * `require-dev` racine n'est installé en `packages` que si un paquet de
     * production l'exige — sinon il appartient à `packages-dev`.
     *
     * @param array<mixed>            $composer
     * @param 'require'|'require-dev' $otherSection
     * @param array<string, true>     $requiredInProduction
     */
    private static function lockLagsBehind(array $composer, string $package, string $otherSection, array $requiredInProduction): bool
    {
        if (!\array_key_exists($package, self::requirements($composer, $otherSection))) {
            return false;
        }

        return 'require' === $otherSection || !isset($requiredInProduction[$package]);
    }

    /**
     * Les paquets qu'un paquet de `packages` exige : ceux que Composer installe
     * en production quoi qu'en dise le `require-dev` de `composer.json`.
     *
     * @param array<mixed> $lock
     *
     * @return array<string, true>
     */
    private static function requiredInProduction(array $lock): array
    {
        $required = [];
        foreach (ComposerFiles::lockedPackages($lock, MalformedLockEntry::Fails) as $package) {
            if ('packages' === $package->section) {
                foreach ($package->requires as $name) {
                    $required[$name] = true;
                }
            }
        }

        return $required;
    }

    /**
     * Une contrainte épingle exactement la version verrouillée quand elle est
     * une version seule ({@see self::SINGLE_VERSION_SHAPE}), qui n'est pas une
     * branche (stabilité `dev` : `dev-main`, `0.16.x-dev`, `0.16.0-dev`), et
     * que Composer la normalise comme la version du lock : `1.0.0-beta1` et
     * `v1.0.0-BETA1`, `1.0.0-b1` et `1.0.0-beta1`, `1.0.0-p1` et
     * `1.0.0-patch1` sont une même version pour lui.
     */
    private static function pinsExactly(string $constraint, string $lockedVersion): bool
    {
        if (1 !== preg_match(self::SINGLE_VERSION_SHAPE, $constraint) || 'dev' === VersionParser::parseStability($constraint)) {
            return false;
        }

        $parser = new VersionParser();
        try {
            return $parser->normalize($constraint) === $parser->normalize($lockedVersion);
        } catch (UnexpectedValueException) {
            // Une forme que Composer ne lit pas comme une version n'épingle rien.
            return false;
        }
    }

    private static function isPinnedFamily(string $package): bool
    {
        return array_any(self::PINNED_PREFIXES, static fn (string $prefix): bool => str_starts_with($package, $prefix));
    }
}
