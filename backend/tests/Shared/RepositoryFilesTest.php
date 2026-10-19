<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Ce que {@see RepositoryFiles} résout et lit (issue #629) : les deux racines,
 * que les tests ne recalculent plus depuis leur propre emplacement, et un
 * fichier lu en entier ou un échec qui le nomme, jamais un texte vide.
 */
final class RepositoryFilesTest extends TestCase
{
    private ?string $scratch = null;

    protected function tearDown(): void
    {
        if (null !== $this->scratch) {
            new Filesystem()->chmod($this->scratch, 0o700, 0o000, true);
            new Filesystem()->remove($this->scratch);
        }
    }

    /**
     * Des fichiers que seule la racine en question porte : un calcul décalé
     * d'un niveau, dans un sens ou dans l'autre, ne les trouve pas. Le
     * conteneur de dev ne monte qu'une partie de la racine du dépôt
     * (docker-compose.yml) : `docker/` en est.
     */
    public function testTheRootsAreTheRepositoryAndItsBackend(): void
    {
        self::assertFileExists(RepositoryFiles::repository().'/docker/php/Dockerfile');
        self::assertFileExists(RepositoryFiles::backend().'/bin/console');
        self::assertSame(RepositoryFiles::repository().'/backend', RepositoryFiles::backend());
    }

    public function testAFileIsReadWhole(): void
    {
        $path = $this->scratchFile('contenu');

        self::assertSame('contenu', RepositoryFiles::read($path));
    }

    /**
     * Un fichier vide est un texte vide légitime : seul l'échec de lecture
     * est refusé.
     */
    public function testAnEmptyFileIsAnEmptyText(): void
    {
        self::assertSame('', RepositoryFiles::read($this->scratchFile('')));
    }

    public function testAMissingFileFailsTheTestNamingIt(): void
    {
        $path = RepositoryFiles::backend().'/absent-'.bin2hex(random_bytes(4)).'.txt';

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($path.' : fichier absent, illisible ou qui n\'est pas un fichier ordinaire.');

        RepositoryFiles::read($path);
    }

    /**
     * `assertFileIsReadable` accepte un répertoire, et `file_get_contents`
     * en rend `false` avec un simple avertissement.
     */
    public function testADirectoryFailsTheTestNamingIt(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(RepositoryFiles::backend().'/config : fichier absent, illisible ou qui n\'est pas un fichier ordinaire.');

        RepositoryFiles::read(RepositoryFiles::backend().'/config');
    }

    public function testAnUnreadableFileFailsTheTestNamingIt(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root lit un fichier sans droit de lecture.');
        }
        $path = $this->scratchFile('secret');
        chmod($path, 0o000);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($path.' : fichier absent, illisible ou qui n\'est pas un fichier ordinaire.');

        RepositoryFiles::read($path);
    }

    private function scratchFile(string $content): string
    {
        $this->scratch = sys_get_temp_dir().'/repository-files-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->scratch.'/file.txt', $content);

        return $this->scratch.'/file.txt';
    }
}
