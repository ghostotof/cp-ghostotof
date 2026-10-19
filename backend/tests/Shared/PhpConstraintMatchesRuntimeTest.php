<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;

/**
 * La contrainte `php` de composer.json nomme la version du runtime, et rien
 * en dessous (issue #429).
 *
 * Le runtime est le même partout — images, CI, conteneur de dev — et c'est lui
 * qui exécute ce test. Une contrainte plus basse autorisait une installation
 * jamais testée ni déployée, et laissait passer une fonction de la version du
 * runtime grâce au seul polyfill transitif (`array_first` pendant #419) :
 * PHPStan, Rector et Psalm lisent la version cible dans cette contrainte.
 * Au passage du runtime à une nouvelle version, ce test rougit : relever la
 * contrainte et remplacer le polyfill de la version quittée, dans le même
 * changement.
 */
final class PhpConstraintMatchesRuntimeTest extends TestCase
{
    public function testTheConstraintIsTheRuntimeVersion(): void
    {
        self::assertSame(\sprintf('>=%d.%d', \PHP_MAJOR_VERSION, \PHP_MINOR_VERSION), $this->composer()['require']['php'] ?? null);
    }

    /**
     * Les fonctions de la version du runtime sont natives : son polyfill est
     * remplacé, comme ceux des versions antérieures, et ne s'installe pas.
     */
    public function testThePolyfillOfTheRuntimeVersionIsReplaced(): void
    {
        $polyfill = \sprintf('symfony/polyfill-php%d%d', \PHP_MAJOR_VERSION, \PHP_MINOR_VERSION);

        self::assertSame('*', $this->composer()['replace'][$polyfill] ?? null, $polyfill.' doit être remplacé.');
    }

    /**
     * @return array{require?: array<string, string>, replace?: array<string, string>}
     */
    private function composer(): array
    {
        $contents = RepositoryFiles::read(RepositoryFiles::backend('composer.json'));

        /** @var array{require?: array<string, string>, replace?: array<string, string>} */
        return json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
    }
}
