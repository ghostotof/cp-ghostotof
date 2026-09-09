<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Manifest;

use App\Portfolio\Watch\Infrastructure\Manifest\FilePackageManifestReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FilePackageManifestReaderTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->manifestPath = sys_get_temp_dir().'/watch-manifest-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }
    }

    private function reader(): FilePackageManifestReader
    {
        return new FilePackageManifestReader($this->manifestPath, new NullLogger());
    }

    private function write(string $content): void
    {
        file_put_contents($this->manifestPath, $content);
    }

    /**
     * « Aucune analyse tentée » : c'est l'état d'un poste de développement, et
     * il ne doit surtout pas se lire comme « aucune vulnérabilité ».
     */
    public function testAnAbsentManifestReadsAsUnknownScope(): void
    {
        self::assertNull($this->reader()->read());
    }

    /**
     * La distinction qui compte : un manifeste vide **existe**. Il dit qu'il
     * n'y avait rien à analyser, ce qui n'est pas la même chose que de n'avoir
     * jamais cherché.
     */
    public function testAnEmptyManifestIsStillAManifest(): void
    {
        $this->write('{"generatedAt":"2026-09-07T12:00:00+00:00","packages":[]}');

        $manifest = $this->reader()->read();

        self::assertNotNull($manifest);
        self::assertSame([], $manifest->packages);
    }

    public function testItReadsBackWhatTheBuilderWrote(): void
    {
        $this->write('{"generatedAt":"2026-09-07T12:00:00+00:00","packages":[
            {"ecosystem":"Packagist","name":"symfony/http-client","version":"8.1.4"},
            {"ecosystem":"npm","name":"vue","version":"3.5.42"}
        ]}');

        $manifest = $this->reader()->read();

        self::assertNotNull($manifest);
        self::assertCount(2, $manifest->packages);
        self::assertSame('symfony/http-client', $manifest->packages[0]->name);
        self::assertSame('npm', $manifest->packages[1]->ecosystem);
        self::assertSame('2026-09-07T12:00:00+00:00', $manifest->generatedAt->format(\DATE_ATOM));
    }

    public function testAMalformedManifestReadsAsUnknownScope(): void
    {
        $this->write('<html>ceci n\'est pas un manifeste</html>');

        self::assertNull($this->reader()->read());
    }

    /**
     * Une entrée amputée est ignorée plutôt que fatale : mieux vaut analyser
     * quatre-vingt-dix-neuf paquets sur cent que de renoncer à tout.
     */
    public function testAnIncompleteEntryIsSkipped(): void
    {
        $this->write('{"packages":[{"name":"orphelin"},{"ecosystem":"npm","name":"vue","version":"3.5.42"}]}');

        $manifest = $this->reader()->read();

        self::assertNotNull($manifest);
        self::assertCount(1, $manifest->packages);
        self::assertSame('vue', $manifest->packages[0]->name);
    }
}
