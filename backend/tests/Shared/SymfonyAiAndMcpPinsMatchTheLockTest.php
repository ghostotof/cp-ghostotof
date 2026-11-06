<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde commun des épinglages exacts des paquets 0.x de Symfony AI et du
 * serveur MCP (ADR 0004 D1 amendée le 2026-11-06, ADR 0006 D7 amendée le
 * même jour, issue #731).
 *
 * Tout paquet `symfony/ai-*`, `symfony/mcp-*` ou `mcp/*` présent dans
 * `composer.lock` doit figurer dans le `require` de `composer.json` avec une
 * contrainte **exacte** (`0.15.0`, ni `^`, ni `~`, ni `*`) égale à la version
 * verrouillée. Ces paquets sont en 0.x, sans promesse de compatibilité : une
 * montée se décide et se lit dans les changelogs amont. Un paquet seulement
 * transitif monterait d'une version 0.x au premier `composer update`, tenu par
 * le seul `^0.15` d'un autre ; c'est ce que #731 a trouvé pour
 * `symfony/ai-platform`, `symfony/ai-generic-platform` et
 * `symfony/ai-open-responses-platform`.
 *
 * Le lock est la source de ce qui s'installe : un paquet que le bundle tirera
 * demain y entre sans que ce test change, et rougit tant qu'il n'est pas
 * épinglé. Une liste de noms écrite ici l'ignorerait. Inversement, un paquet
 * de ces familles exigé par `composer.json` mais absent du lock dit un lock
 * désynchronisé.
 *
 * Il ne couvre pas les autres paquets pinnés à la main hors de ces familles
 * (`league/oauth2-server`, `defuse/php-encryption`) : ceux-là restent gardés
 * par {@see \App\Tests\Mcp\Presentation\McpEndpointTest::testTheMcpPackagesArePinnedExactly()}.
 */
final class SymfonyAiAndMcpPinsMatchTheLockTest extends TestCase
{
    /** Les familles 0.x pinnées en version exacte (ADR 0004 D1, ADR 0006 D7). */
    private const array PINNED_PREFIXES = ['symfony/ai-', 'symfony/mcp-', 'mcp/'];

    /** Une version exacte, telle que la contrainte doit l'écrire. */
    private const string EXACT_VERSION = '/^\d+\.\d+\.\d+\z/';

    public function testEveryPackageOfTheFamiliesInTheLockIsPinnedAtItsLockedVersion(): void
    {
        $composer = self::decoded('composer.json');
        $lock = self::decoded('composer.lock');

        // Sans ces deux-là, le test lirait un autre lock que celui du projet.
        $locked = $this->lockedVersions($lock);
        self::assertArrayHasKey('symfony/ai-bundle', $locked, 'composer.lock ne contient plus symfony/ai-bundle : le test lit-il le bon lock ?');
        self::assertArrayHasKey('symfony/mcp-bundle', $locked, 'composer.lock ne contient plus symfony/mcp-bundle : le test lit-il le bon lock ?');

        self::assertSame([], $this->violations($composer, $lock), $this->failure($this->violations($composer, $lock)));
    }

    /**
     * @return iterable<string, array{array<mixed>, array<mixed>, list<string>}> un `composer.json`, un lock, et les paquets fautifs attendus
     */
    public static function composerFiles(): iterable
    {
        $lock = self::lock(['symfony/ai-bundle' => 'v0.15.0', 'symfony/ai-platform' => 'v0.15.0', 'mcp/sdk' => 'v0.8.1', 'symfony/framework-bundle' => 'v8.1.10']);
        $pinned = ['symfony/ai-bundle' => '0.15.0', 'symfony/ai-platform' => '0.15.0', 'mcp/sdk' => '0.8.1', 'symfony/framework-bundle' => '8.1.*'];

        yield 'chaque paquet des familles pinné à sa version' => [self::composer($pinned), $lock, []];
        yield 'un paquet hors familles reste libre' => [self::composer([...$pinned, 'symfony/framework-bundle' => '^8.1']), $lock, []];
        yield 'un paquet seulement transitif' => [self::composer(array_diff_key($pinned, ['symfony/ai-platform' => true])), $lock, ['symfony/ai-platform']];
        yield 'une contrainte large' => [self::composer([...$pinned, 'symfony/ai-bundle' => '^0.15']), $lock, ['symfony/ai-bundle']];
        yield 'une contrainte tilde' => [self::composer([...$pinned, 'mcp/sdk' => '~0.8.1']), $lock, ['mcp/sdk']];
        yield 'une version exacte qui n\'est pas celle du lock' => [self::composer([...$pinned, 'symfony/ai-platform' => '0.14.1']), $lock, ['symfony/ai-platform']];
        yield 'une contrainte qui n\'est pas une chaîne' => [self::composer([...$pinned, 'mcp/sdk' => ['0.8.1']]), $lock, ['mcp/sdk']];
        yield 'un pin en require-dev seulement' => [
            self::composer(array_diff_key($pinned, ['mcp/sdk' => true]), ['mcp/sdk' => '0.8.1']),
            $lock,
            ['mcp/sdk'],
        ];
        yield 'un paquet des familles du lock de dev' => [
            self::composer($pinned),
            self::lock(['symfony/ai-bundle' => 'v0.15.0', 'symfony/ai-platform' => 'v0.15.0', 'mcp/sdk' => 'v0.8.1'], ['symfony/mcp-bundle' => 'v0.14.1']),
            ['symfony/mcp-bundle'],
        ];
        yield 'un pin dont le paquet a quitté le lock' => [self::composer([...$pinned, 'symfony/ai-agent' => '0.15.0']), $lock, ['symfony/ai-agent']];

        // Le vrai composer.json privé d'un de ses pins, contre le vrai lock :
        // le garde rougit sur l'état réel du dépôt, pas seulement sur des fixtures.
        $realComposer = self::decoded('composer.json');
        $realRequire = \is_array($realComposer['require'] ?? null) ? $realComposer['require'] : [];
        unset($realRequire['symfony/ai-generic-platform']);
        yield 'le composer.json réel sans le pin de symfony/ai-generic-platform' => [
            [...$realComposer, 'require' => $realRequire],
            self::decoded('composer.lock'),
            ['symfony/ai-generic-platform'],
        ];
    }

    /**
     * @param array<mixed> $composer
     * @param array<mixed> $lock
     * @param list<string> $expected
     */
    #[DataProvider('composerFiles')]
    public function testItNamesEveryPackageThatIsNotPinnedAtItsLockedVersion(array $composer, array $lock, array $expected): void
    {
        self::assertSame($expected, array_keys($this->violations($composer, $lock)));
    }

    public function testTheFailureNamesThePackageTheLockedVersionAndTheConstraintFound(): void
    {
        $message = $this->failure($this->violations(self::composer(['symfony/ai-bundle' => '^0.15']), self::lock(['symfony/ai-bundle' => 'v0.15.0', 'mcp/sdk' => 'v0.8.1'])));

        self::assertStringContainsString('symfony/ai-bundle : verrouillé en 0.15.0, contrainte « ^0.15 »', $message);
        self::assertStringContainsString('mcp/sdk : verrouillé en 0.8.1, absent du require', $message);
        self::assertStringContainsString('"symfony/ai-bundle": "0.15.0"', $message, 'Le message donne la ligne à écrire.');
    }

    /**
     * Les paquets des familles qui ne respectent pas la règle, chacun avec sa
     * raison, dans l'ordre du lock puis du `require`.
     *
     * @param array<mixed> $composer le contenu décodé de `composer.json`
     * @param array<mixed> $lock     le contenu décodé de `composer.lock`
     *
     * @return array<string, string> paquet => raison
     */
    private function violations(array $composer, array $lock): array
    {
        $require = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $locked = $this->lockedVersions($lock);
        $violations = [];

        foreach ($locked as $package => $version) {
            $constraint = $require[$package] ?? null;
            $expected = \sprintf('écrire "%s": "%s" dans le require de composer.json', $package, $version);

            if (null === $constraint) {
                $violations[$package] = \sprintf('%s : verrouillé en %s, absent du require — %s.', $package, $version, $expected);
            } elseif (!\is_string($constraint) || 1 !== preg_match(self::EXACT_VERSION, $constraint) || $constraint !== $version) {
                $found = \is_string($constraint) ? $constraint : get_debug_type($constraint);
                $violations[$package] = \sprintf('%s : verrouillé en %s, contrainte « %s » — %s.', $package, $version, $found, $expected);
            }
        }

        // Un pin des familles dont le paquet n'est pas dans le lock : lock désynchronisé.
        foreach (array_keys($require) as $package) {
            if (\is_string($package) && $this->isPinnedFamily($package) && !\array_key_exists($package, $locked)) {
                $violations[$package] = \sprintf('%s : exigé par composer.json mais absent de composer.lock — lancer composer update %s.', $package, $package);
            }
        }

        return $violations;
    }

    /**
     * Les versions verrouillées des paquets des familles, `packages` et
     * `packages-dev` confondus, sans le `v` des tags.
     *
     * @param array<mixed> $lock
     *
     * @return array<string, string>
     */
    private function lockedVersions(array $lock): array
    {
        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? [];
            self::assertIsArray($packages);
            foreach ($packages as $package) {
                self::assertIsArray($package);
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                self::assertIsString($name);
                self::assertIsString($version);
                if ($this->isPinnedFamily($name)) {
                    $versions[$name] = str_starts_with($version, 'v') ? substr($version, 1) : $version;
                }
            }
        }

        return $versions;
    }

    private function isPinnedFamily(string $package): bool
    {
        return array_any(self::PINNED_PREFIXES, static fn (string $prefix): bool => str_starts_with($package, $prefix));
    }

    /**
     * @param array<string, string> $violations
     */
    private function failure(array $violations): string
    {
        $lines = [];
        foreach ($violations as $reason) {
            $lines[] = '- '.$reason;
        }

        return "Paquets 0.x de Symfony AI ou du serveur MCP mal épinglés (ADR 0004 D1, ADR 0006 D7) :\n".implode("\n", $lines);
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(string $file): array
    {
        $decoded = json_decode(RepositoryFiles::read(RepositoryFiles::backend($file)), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $require
     * @param array<string, mixed> $requireDev
     *
     * @return array<mixed>
     */
    private static function composer(array $require, array $requireDev = []): array
    {
        return ['require' => ['php' => '>=8.5', ...$require], 'require-dev' => $requireDev];
    }

    /**
     * @param array<string, string> $packages    nom => version du lock
     * @param array<string, string> $devPackages nom => version du lock
     *
     * @return array<mixed>
     */
    private static function lock(array $packages, array $devPackages = []): array
    {
        $lock = ['packages' => [], 'packages-dev' => []];
        foreach (['packages' => $packages, 'packages-dev' => $devPackages] as $section => $versions) {
            foreach ($versions as $name => $version) {
                $lock[$section][] = ['name' => $name, 'version' => $version];
            }
        }

        return $lock;
    }
}
