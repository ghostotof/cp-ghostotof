<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ComposerFiles;
use App\Tests\Support\LockedPackage;
use App\Tests\Support\MalformedLockEntry;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'aide commune de lecture de `composer.json` et `composer.lock`
 * ({@see ComposerFiles}), partagée par le garde des épinglages exacts
 * ({@see SymfonyAiAndMcpPinsMatchTheLockTest}) et celui de la famille
 * d'API Platform
 * ({@see \App\Tests\Shared\Infrastructure\ApiPlatform\ApiPlatformPackagesShareOneVersionTest}).
 *
 * Les deux gardes n'ont pas la même tolérance, et chacun garde la sienne :
 * une entrée mal formée est ignorée par le second
 * ({@see MalformedLockEntry::Skipped}), elle fait échouer le premier
 * ({@see MalformedLockEntry::Fails}).
 */
final class ComposerFilesTest extends TestCase
{
    public function testItReadsBothSectionsInLockOrderAndKeepsTheVersionAsWritten(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'symfony/ai-bundle', 'version' => 'v0.15.0', 'require' => ['symfony/ai-platform' => '^0.15', 'php' => '>=8.2']],
                ['name' => 'mcp/sdk', 'version' => '0.8.1'],
            ],
            'packages-dev' => [
                ['name' => 'symfony/ai-platform', 'version' => 'v1.0.0-BETA1'],
            ],
        ];

        foreach (MalformedLockEntry::cases() as $malformed) {
            self::assertEquals([
                new LockedPackage('packages', 'symfony/ai-bundle', 'v0.15.0', ['symfony/ai-platform', 'php']),
                new LockedPackage('packages', 'mcp/sdk', '0.8.1', []),
                new LockedPackage('packages-dev', 'symfony/ai-platform', 'v1.0.0-BETA1', []),
            ], ComposerFiles::lockedPackages($lock, $malformed), $malformed->name);
        }
    }

    public function testTheVersionWithoutTagPrefixDropsOnlyALeadingV(): void
    {
        self::assertSame('0.15.0', new LockedPackage('packages', 'a/b', 'v0.15.0', [])->versionWithoutTagPrefix());
        self::assertSame('1.0.0-BETA1', new LockedPackage('packages', 'a/b', 'v1.0.0-BETA1', [])->versionWithoutTagPrefix());
        self::assertSame('0.8.1', new LockedPackage('packages', 'a/b', '0.8.1', [])->versionWithoutTagPrefix());
        self::assertSame('dev-main', new LockedPackage('packages', 'a/b', 'dev-main', [])->versionWithoutTagPrefix());
    }

    public function testAMissingSectionIsEmptyUnderBothTolerances(): void
    {
        foreach (MalformedLockEntry::cases() as $malformed) {
            self::assertSame([], ComposerFiles::lockedPackages([], $malformed), $malformed->name);
        }
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedLocks(): iterable
    {
        $valid = ['name' => 'mcp/sdk', 'version' => '0.8.1'];

        yield 'une section qui n\'est pas une liste' => [['packages' => [$valid], 'packages-dev' => 'pas une liste']];
        yield 'une entrée qui n\'est pas un objet' => [['packages' => [$valid, 'pas un objet']]];
        yield 'une entrée sans nom' => [['packages' => [$valid, ['version' => '1.0.0']]]];
        yield 'une entrée sans version' => [['packages' => [$valid, ['name' => 'a/b']]]];
        yield 'une version qui n\'est pas une chaîne' => [['packages' => [$valid, ['name' => 'a/b', 'version' => 1]]]];
    }

    /**
     * @param array<mixed> $lock
     */
    #[DataProvider('malformedLocks')]
    public function testAMalformedEntryIsSkippedOrFailsTheTestAsAsked(array $lock): void
    {
        self::assertEquals([new LockedPackage('packages', 'mcp/sdk', '0.8.1', [])], ComposerFiles::lockedPackages($lock, MalformedLockEntry::Skipped));

        $this->expectException(AssertionFailedError::class);
        ComposerFiles::lockedPackages($lock, MalformedLockEntry::Fails);
    }

    /** Un `require` qui n'est pas un objet ne fait échouer aucune des deux lectures : seuls le nom et la version comptent. */
    public function testAMalformedRequireIsReadAsEmptyUnderBothTolerances(): void
    {
        $lock = ['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'require' => 'pas un objet']]];

        foreach (MalformedLockEntry::cases() as $malformed) {
            self::assertEquals([new LockedPackage('packages', 'a/b', '1.0.0', [])], ComposerFiles::lockedPackages($lock, $malformed), $malformed->name);
        }
    }

    public function testItDecodesTheRealFilesOfTheBackend(): void
    {
        self::assertArrayHasKey('require', ComposerFiles::decoded('composer.json'));
        self::assertArrayHasKey('packages', ComposerFiles::decoded('composer.lock'));
    }
}
