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
    /** Les deux paquets d'API Platform que `composer.json` nomme ; sans eux, le test ne lirait pas le bon lock. */
    private const array ROOTS = ['api-platform/symfony', 'api-platform/doctrine-orm'];

    public function testEveryApiPlatformPackageOfTheLockIsAtTheSameVersion(): void
    {
        $lock = json_decode(RepositoryFiles::read(RepositoryFiles::backend('composer.lock')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);
        $versions = ApiPlatformLockFamily::versions($lock, self::ROOTS);

        foreach (self::ROOTS as $root) {
            self::assertArrayHasKey($root, $versions);
        }

        self::assertCount(1, array_unique($versions), $this->failure($versions));
    }

    /**
     * @return iterable<string, array{array<mixed>, list<string>, bool}> un lock, ses racines, et s'il doit passer
     */
    public static function locks(): iterable
    {
        $family = ['api-platform/symfony' => ['v4.4.6', ['api-platform/state', 'symfony/framework-bundle']], 'api-platform/state' => ['v4.4.6', []], 'api-platform/doctrine-orm' => ['v4.4.6', ['api-platform/state']]];

        yield 'la famille au complet, à une version' => [self::lock($family), self::ROOTS, true];
        yield 'un outil api-platform/* hors famille, à sa propre version' => [
            self::lock([...$family, 'api-platform/outil' => ['1.2.0', []]]),
            self::ROOTS,
            true,
        ];
        yield 'une divergence réelle dans la famille' => [
            self::lock([...$family, 'api-platform/state' => ['v4.4.0', []]]),
            self::ROOTS,
            false,
        ];
        yield 'une divergence d\'un composant que seul un autre tire' => [
            self::lock([
                'api-platform/symfony' => ['v4.4.6', ['api-platform/state']],
                'api-platform/state' => ['v4.4.6', ['api-platform/metadata']],
                'api-platform/metadata' => ['v4.3.0', []],
                'api-platform/doctrine-orm' => ['v4.4.6', []],
            ]),
            self::ROOTS,
            false,
        ];
        yield 'un outil hors famille et une divergence dans la famille' => [
            self::lock([...$family, 'api-platform/outil' => ['1.2.0', []], 'api-platform/state' => ['v4.3.19', []]]),
            self::ROOTS,
            false,
        ];
    }

    /**
     * @param array<mixed> $lock
     * @param list<string> $roots
     */
    #[DataProvider('locks')]
    public function testItJudgesTheFamilyNotTheVendorPrefix(array $lock, array $roots, bool $shouldPass): void
    {
        $versions = ApiPlatformLockFamily::versions($lock, $roots);

        self::assertSame($shouldPass, 1 === \count(array_unique($versions)), $this->failure($versions));
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
