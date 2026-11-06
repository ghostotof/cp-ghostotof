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
 * `composer.lock` doit être exigé par `composer.json` avec une contrainte
 * **exacte** égale à la version verrouillée (sans le `v` du tag) : trois
 * nombres, et au plus un suffixe de pré-version (`1.0.0-BETA1`, `0.16.0-RC2`).
 * Jamais une branche (`dev-main`, `0.16.x-dev`), ni un opérateur, un joker,
 * une alternative ou une espace. Ces paquets sont en 0.x, sans promesse de
 * compatibilité : une montée se décide et se lit dans les changelogs amont. Un
 * paquet seulement transitif monterait d'une version 0.x au premier
 * `composer update`, tenu par le seul `^0.15` d'un autre ; c'est ce que #731
 * a trouvé pour `symfony/ai-platform`, `symfony/ai-generic-platform` et
 * `symfony/ai-open-responses-platform`.
 *
 * La section suit le lock : un paquet de `packages` (installé en production)
 * s'épingle dans `require`, un paquet de `packages-dev` dans `require-dev`.
 * Le premier test exige en outre que les deux bundles et `mcp/sdk` restent
 * dans `packages` : en `require-dev`, ils manqueraient à l'image de production
 * (`composer install --no-dev`).
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
 *
 * Les aides du fournisseur de données (`composer()`, `lock()`) sont
 * statiques, comme lui par contrat PHPUnit ; les autres, appelées par les
 * seuls tests, sont d'instance (règle Rector du dépôt,
 * LocallyCalledStaticMethodToNonStaticRector).
 */
final class SymfonyAiAndMcpPinsMatchTheLockTest extends TestCase
{
    /** Les familles 0.x pinnées en version exacte (ADR 0004 D1, ADR 0006 D7). */
    private const array PINNED_PREFIXES = ['symfony/ai-', 'symfony/mcp-', 'mcp/'];

    /**
     * Une version exacte, telle que la contrainte doit l'écrire : trois
     * nombres, puis au plus un suffixe de pré-version `alpha`, `beta` ou `RC`
     * (la casse que Composer admet). `dev-*`, `.x-dev` et `-dev` n'y entrent
     * pas : ce sont des branches.
     */
    private const string EXACT_VERSION = '/^\d+\.\d+\.\d+(?:-(?:alpha|beta|RC)\.?\d*)?\z/i';

    /** La section de `composer.json` où s'épingle un paquet, selon la section du lock qui l'installe. */
    private const array REQUIRE_SECTION_OF = ['packages' => 'require', 'packages-dev' => 'require-dev'];

    /**
     * Paquets que le projet installe en production et qui doivent y rester.
     * Sans eux dans `packages`, le test lirait un autre lock que celui du
     * projet, ou une image privée du serveur MCP ou de l'assistant.
     */
    private const array PRODUCTION_ANCHORS = ['symfony/ai-bundle', 'symfony/mcp-bundle', 'mcp/sdk'];

    public function testEveryPackageOfTheFamiliesInTheLockIsPinnedAtItsLockedVersion(): void
    {
        $composer = $this->decoded('composer.json');
        $lock = $this->decoded('composer.lock');

        $locked = $this->lockedPackages($lock);
        foreach (self::PRODUCTION_ANCHORS as $package) {
            self::assertSame('packages', $locked[$package]['section'] ?? null, \sprintf('%s n\'est plus dans les paquets de production de composer.lock : le test lit-il le bon lock ?', $package));
        }

        $violations = $this->violations($composer, $lock);
        self::assertSame([], $violations, $this->failure($violations));
    }

    /**
     * Sur les vrais fichiers du dépôt : chaque paquet des familles du lock,
     * son pin retiré seul, est le seul nommé. Le garde rougit sur l'état
     * réel, pas seulement sur des fixtures, quel que soit le paquet.
     */
    public function testRemovingAnyRealPinNamesThatPackageAlone(): void
    {
        $composer = $this->decoded('composer.json');
        $lock = $this->decoded('composer.lock');
        $locked = $this->lockedPackages($lock);
        self::assertNotSame([], $locked, 'composer.lock ne contient aucun paquet des familles.');

        foreach ($locked as $package => ['section' => $section]) {
            $requireSection = self::REQUIRE_SECTION_OF[$section];
            $requirements = $composer[$requireSection] ?? [];
            self::assertIsArray($requirements);
            unset($requirements[$package]);

            self::assertSame([$package], array_keys($this->violations([...$composer, $requireSection => $requirements], $lock)), $package);
        }
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
        yield 'un paquet de production épinglé seulement dans require-dev' => [
            self::composer(array_diff_key($pinned, ['mcp/sdk' => true]), ['mcp/sdk' => '0.8.1']),
            $lock,
            ['mcp/sdk'],
        ];
        yield 'un paquet du lock de dev sans pin' => [
            self::composer($pinned),
            self::lock(['symfony/ai-bundle' => 'v0.15.0', 'symfony/ai-platform' => 'v0.15.0', 'mcp/sdk' => 'v0.8.1'], ['symfony/mcp-bundle' => 'v0.14.1']),
            ['symfony/mcp-bundle'],
        ];
        yield 'un pin dont le paquet a quitté le lock' => [self::composer([...$pinned, 'symfony/ai-agent' => '0.15.0']), $lock, ['symfony/ai-agent']];

        // Pré-versions : un pin exact égal au lock (normalisé sans `v`) est admis.
        $beta = self::lock(['symfony/ai-bundle' => 'v1.0.0-BETA1', 'symfony/ai-platform' => '0.16.0-RC2', 'mcp/sdk' => 'v0.8.1']);
        $betaPinned = ['symfony/ai-bundle' => '1.0.0-BETA1', 'symfony/ai-platform' => '0.16.0-RC2', 'mcp/sdk' => '0.8.1'];
        yield 'une pré-version épinglée à sa version du lock' => [self::composer($betaPinned), $beta, []];
        yield 'une pré-version qui n\'est pas celle du lock' => [self::composer([...$betaPinned, 'symfony/ai-bundle' => '1.0.0-BETA2']), $beta, ['symfony/ai-bundle']];

        // Une branche n'est jamais un pin exact, même écrite comme le lock l'écrit.
        yield 'une branche dev-*' => [
            self::composer([...$pinned, 'symfony/ai-platform' => 'dev-main']),
            self::lock(['symfony/ai-bundle' => 'v0.15.0', 'symfony/ai-platform' => 'dev-main', 'mcp/sdk' => 'v0.8.1']),
            ['symfony/ai-platform'],
        ];
        yield 'un alias de branche .x-dev' => [
            self::composer([...$pinned, 'symfony/ai-platform' => '0.16.x-dev']),
            self::lock(['symfony/ai-bundle' => 'v0.15.0', 'symfony/ai-platform' => '0.16.x-dev', 'mcp/sdk' => 'v0.8.1']),
            ['symfony/ai-platform'],
        ];
        yield 'un suffixe -dev' => [
            self::composer([...$pinned, 'symfony/ai-platform' => '0.16.0-dev']),
            self::lock(['symfony/ai-bundle' => 'v0.15.0', 'symfony/ai-platform' => '0.16.0-dev', 'mcp/sdk' => 'v0.8.1']),
            ['symfony/ai-platform'],
        ];

        // Toute contrainte qui n'est pas une version seule, même satisfaite par le lock.
        foreach (['*', '0.15.*', '>=0.15.0', '=0.15.0', '0.15.0 || 0.16.0', '0.15.0|0.16.0', '0.15.0 ', ' 0.15.0', '0.15.0 as 0.14.0', 'v0.15.0', '0.15.0@stable'] as $constraint) {
            yield \sprintf('la contrainte « %s »', $constraint) => [self::composer([...$pinned, 'symfony/ai-bundle' => $constraint]), $lock, ['symfony/ai-bundle']];
        }

        // require-dev : un paquet du lock de dev s'épingle dans require-dev ; un paquet de production reste dans require.
        $devLock = self::lock(['symfony/ai-bundle' => 'v0.15.0', 'symfony/ai-platform' => 'v0.15.0', 'mcp/sdk' => 'v0.8.1'], ['symfony/mcp-bundle' => 'v0.14.1']);
        yield 'un paquet du lock de dev épinglé dans require-dev' => [self::composer($pinned, ['symfony/mcp-bundle' => '0.14.1']), $devLock, []];
        yield 'un paquet du lock de dev épinglé dans require' => [self::composer([...$pinned, 'symfony/mcp-bundle' => '0.14.1']), $devLock, ['symfony/mcp-bundle']];
        yield 'un paquet du lock de dev en require-dev, contrainte large' => [self::composer($pinned, ['symfony/mcp-bundle' => '^0.14']), $devLock, ['symfony/mcp-bundle']];
        yield 'un pin de require-dev dont le paquet a quitté le lock' => [self::composer($pinned, ['symfony/ai-agent' => '0.15.0']), $lock, ['symfony/ai-agent']];

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
        $message = $this->failure($this->violations(self::composer(['symfony/ai-bundle' => '^0.15']), self::lock(['symfony/ai-bundle' => 'v0.15.0', 'mcp/sdk' => 'v0.8.1'], ['symfony/mcp-bundle' => 'v0.14.1'])));

        self::assertStringContainsString('symfony/ai-bundle : verrouillé en 0.15.0, contrainte « ^0.15 »', $message);
        self::assertStringContainsString('mcp/sdk : verrouillé en 0.8.1, absent du require', $message);
        self::assertStringContainsString('"symfony/ai-bundle": "0.15.0"', $message, 'Le message donne la ligne à écrire.');
        self::assertStringContainsString('symfony/mcp-bundle : verrouillé en 0.14.1, absent du require-dev', $message, 'Le message nomme la section que le lock impose.');
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
    private function violations(array $composer, array $lock): array
    {
        $locked = $this->lockedPackages($lock);
        $violations = [];

        foreach ($locked as $package => ['section' => $section, 'version' => $version]) {
            $requireSection = self::REQUIRE_SECTION_OF[$section];
            $constraint = $this->requirements($composer, $requireSection)[$package] ?? null;
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
            foreach (array_keys($this->requirements($composer, $requireSection)) as $package) {
                if (\is_string($package) && $this->isPinnedFamily($package) && !\array_key_exists($package, $locked)) {
                    $violations[$package] = \sprintf('%s : exigé par le %s de composer.json mais absent de composer.lock — lancer composer update %s.', $package, $requireSection, $package);
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<mixed> $composer
     *
     * @return array<mixed>
     */
    private function requirements(array $composer, string $section): array
    {
        return \is_array($composer[$section] ?? null) ? $composer[$section] : [];
    }

    /**
     * Les paquets des familles dans le lock, `packages` et `packages-dev`
     * confondus : la section qui les installe et leur version, sans le `v`
     * des tags. Une entrée mal formée fait échouer le test.
     *
     * @param array<mixed> $lock
     *
     * @return array<string, array{section: key-of<self::REQUIRE_SECTION_OF>, version: string}>
     */
    private function lockedPackages(array $lock): array
    {
        $locked = [];
        foreach (array_keys(self::REQUIRE_SECTION_OF) as $section) {
            $packages = $lock[$section] ?? [];
            self::assertIsArray($packages);
            foreach ($packages as $package) {
                self::assertIsArray($package);
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                self::assertIsString($name);
                self::assertIsString($version);
                if ($this->isPinnedFamily($name)) {
                    $locked[$name] = ['section' => $section, 'version' => str_starts_with($version, 'v') ? substr($version, 1) : $version];
                }
            }
        }

        return $locked;
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
    private function decoded(string $file): array
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
