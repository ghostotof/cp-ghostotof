<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\ApiPlatformLockFamily;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tous les paquets de la famille d'API Platform dans le lock sont à la même
 * version (issue #701).
 *
 * C'est la cause même de l'incident : le bundle était pinné en 4.3.19 pendant
 * que `serializer`, `state`, `metadata`… montaient en 4.4.0 par leurs
 * contraintes larges. Le bundle câblait `ItemNormalizer` pour dénormaliser,
 * les composants le dépréciaient, et `POST /api/contact` écrivait une
 * dépréciation dans les journaux de production sans qu'aucun test ne rougisse.
 * Les composants d'API Platform se versionnent ensemble (même monorepo, mêmes
 * tags) : un lock qui les mélange est un état que personne n'a testé.
 *
 * La famille n'est pas « tout `api-platform/*` » : un outil du même
 * éditeur hors monorepo a ses propres versions. {@see ApiPlatformLockFamily}
 * la lit dans le graphe du lock, à partir des paquets que `composer.json`
 * exige, et dit pourquoi ce critère plutôt qu'une liste de noms.
 *
 * Le test lit `composer.lock`, la seule source de ce qui s'installe — la
 * contrainte de `composer.json` ne dit rien des paquets que le bundle tire.
 */
final class ApiPlatformPackagesShareOneVersionTest extends TestCase
{
    public function testEveryApiPlatformPackageOfTheLockIsAtTheSameVersion(): void
    {
        $composer = json_decode(RepositoryFiles::read(RepositoryFiles::backend('composer.json')), true, flags: \JSON_THROW_ON_ERROR);
        $lock = json_decode(RepositoryFiles::read(RepositoryFiles::backend('composer.lock')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($lock);
        $roots = ApiPlatformLockFamily::roots($composer);
        $versions = ApiPlatformLockFamily::versions($lock, $roots);

        // Sans racine, ou avec une racine absente du lock, le test ne lirait pas le bon lock.
        self::assertNotSame([], $roots, 'composer.json n\'exige aucun paquet api-platform/*.');
        foreach ($roots as $root) {
            self::assertArrayHasKey($root, $versions);
        }

        self::assertCount(1, array_unique($versions), $this->failure($versions));
    }

    /**
     * @return iterable<string, array{array<mixed>, array<mixed>, bool}> un `composer.json`, un lock, et s'il doit passer
     */
    public static function locks(): iterable
    {
        $family = ['api-platform/symfony' => ['v4.4.6', ['api-platform/state', 'symfony/framework-bundle']], 'api-platform/state' => ['v4.4.6', []], 'api-platform/doctrine-orm' => ['v4.4.6', ['api-platform/state']]];
        $composer = self::composer(['api-platform/symfony', 'api-platform/doctrine-orm']);

        yield 'la famille au complet, à une version' => [$composer, self::lock($family), true];
        yield 'un outil api-platform/* hors famille, à sa propre version' => [
            $composer,
            self::lock([...$family, 'api-platform/outil' => ['1.2.0', []]]),
            true,
        ];
        yield 'une divergence réelle dans la famille' => [
            $composer,
            self::lock([...$family, 'api-platform/state' => ['v4.4.0', []]]),
            false,
        ];
        yield 'une divergence d\'un composant que seul un autre tire' => [
            $composer,
            self::lock([
                'api-platform/symfony' => ['v4.4.6', ['api-platform/state']],
                'api-platform/state' => ['v4.4.6', ['api-platform/metadata']],
                'api-platform/metadata' => ['v4.3.0', []],
                'api-platform/doctrine-orm' => ['v4.4.6', []],
            ]),
            false,
        ];
        yield 'un outil hors famille et une divergence dans la famille' => [
            $composer,
            self::lock([...$family, 'api-platform/outil' => ['1.2.0', []], 'api-platform/state' => ['v4.3.19', []]]),
            false,
        ];
        // D8 : les racines sont ce que composer.json exige, pas une liste écrite
        // dans le test. Un composant exigé directement, que personne d'autre ne
        // tire, entre dans la famille le jour où on l'ajoute à composer.json.
        yield 'D8 : un composant exigé directement (require) qui diverge' => [
            self::composer(['api-platform/symfony', 'api-platform/doctrine-orm', 'api-platform/graphql']),
            self::lock([...$family, 'api-platform/graphql' => ['v4.3.0', []]]),
            false,
        ];
        yield 'D8 : une racine de require-dev qui diverge' => [
            self::composer(['api-platform/symfony'], ['api-platform/graphql']),
            self::lock([...$family, 'api-platform/graphql' => ['v4.3.0', []]]),
            false,
        ];
        yield 'D8 : le même composant, pas exigé, n\'est pas de la famille' => [
            $composer,
            self::lock([...$family, 'api-platform/graphql' => ['v4.3.0', []]]),
            true,
        ];
    }

    /**
     * @param array<mixed> $composer
     * @param array<mixed> $lock
     */
    #[DataProvider('locks')]
    public function testItJudgesTheFamilyNotTheVendorPrefix(array $composer, array $lock, bool $shouldPass): void
    {
        $versions = ApiPlatformLockFamily::versions($lock, ApiPlatformLockFamily::roots($composer));

        self::assertSame($shouldPass, 1 === \count(array_unique($versions)), $this->failure($versions));
    }

    public function testTheRootsAreTheApiPlatformPackagesComposerJsonRequires(): void
    {
        $composer = self::composer(['api-platform/symfony', 'symfony/framework-bundle', 'api-platform/symfony'], ['api-platform/graphql', 'phpunit/phpunit', 'api-platform-bridge/x']);

        self::assertSame(['api-platform/symfony', 'api-platform/graphql'], ApiPlatformLockFamily::roots($composer));
        self::assertSame([], ApiPlatformLockFamily::roots([]));
    }

    public function testTheFailureNamesOnlyTheFamilyAndACommandThatCanRun(): void
    {
        $lock = self::lock([
            'api-platform/symfony' => ['v4.4.6', ['api-platform/state']],
            'api-platform/state' => ['v4.4.0', []],
            'api-platform/outil' => ['1.2.0', []],
        ]);
        $message = $this->failure(ApiPlatformLockFamily::versions($lock, ['api-platform/symfony']));

        self::assertStringContainsString('api-platform/state v4.4.0', $message);
        self::assertStringNotContainsString('api-platform/outil', $message, 'Un paquet hors famille ne se met pas à jour avec elle.');
        self::assertStringContainsString('composer update api-platform/symfony api-platform/state', $message);
        self::assertStringNotContainsString('api-platform/*', $message);
    }

    /**
     * @param list<string> $require    les paquets de `require`
     * @param list<string> $requireDev ceux de `require-dev`
     *
     * @return array<mixed>
     */
    private static function composer(array $require, array $requireDev = []): array
    {
        return ['require' => array_fill_keys($require, '*'), 'require-dev' => array_fill_keys($requireDev, '*')];
    }

    /**
     * @param array<string, array{string, list<string>}> $packages nom => [version, paquets exigés]
     *
     * @return array<mixed>
     */
    private static function lock(array $packages): array
    {
        $entries = [];
        foreach ($packages as $name => [$version, $required]) {
            $entries[] = ['name' => $name, 'version' => $version, 'require' => array_fill_keys($required, '*')];
        }

        return ['packages' => $entries, 'packages-dev' => []];
    }

    /**
     * @param array<string, string> $versions
     */
    private function failure(array $versions): string
    {
        $lines = [];
        foreach ($versions as $name => $version) {
            $lines[] = \sprintf(' - %s %s', $name, $version);
        }

        return "Les paquets de la famille d'API Platform du lock ne sont pas tous à la même version :\n".implode("\n", $lines)
            ."\nMontez-les ensemble : composer update ".implode(' ', array_keys($versions)).'.';
    }
}
