<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Presentation\Command;

use App\Portfolio\Watch\Domain\Service\PackageManifestBuilderInterface;
use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Domain\ValueObject\PackageManifest;
use App\Portfolio\Watch\Presentation\Command\BuildPackageManifestCommand;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test unitaire : la commande écrit un fichier, et l'exécuter à travers le
 * conteneur écraserait le manifeste réel du projet à chaque passage de la
 * suite. Le constructeur est donc substitué ; ce qui est vérifié ici, c'est ce
 * que la commande rapporte de son résultat.
 */
final class BuildPackageManifestCommandTest extends TestCase
{
    private PackageManifestBuilderInterface&Stub $builder;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->builder = self::createStub(PackageManifestBuilderInterface::class);
        $this->tester = new CommandTester(new BuildPackageManifestCommand($this->builder));
    }

    /**
     * @param list<PackageCoordinates> $packages
     */
    private function givenManifest(array $packages): void
    {
        $this->builder->method('build')->willReturn(
            new PackageManifest(new \DateTimeImmutable('2026-09-07 12:00:00'), $packages),
        );
    }

    public function testItReportsTheScopePerEcosystem(): void
    {
        $this->givenManifest([
            new PackageCoordinates(PackageCoordinates::ECOSYSTEM_PACKAGIST, 'symfony/http-client', '8.1.4'),
            new PackageCoordinates(PackageCoordinates::ECOSYSTEM_PACKAGIST, 'api-platform/core', '4.3.17'),
            new PackageCoordinates(PackageCoordinates::ECOSYSTEM_NPM, 'vue', '3.5.42'),
        ]);

        $exitCode = $this->tester->execute([]);
        $display = $this->tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Packagist', $display);
        self::assertStringContainsString('npm', $display);
        self::assertStringContainsString('3 paquets', $display);
    }

    /**
     * Un manifeste vide n'est pas un échec — il n'y avait rien à relever — mais
     * il mérite d'être signalé : en production, c'est le symptôme de fichiers
     * de verrouillage introuvables, et donc d'une analyse qui portera sur rien.
     */
    public function testAnEmptyScopeSucceedsButWarns(): void
    {
        $this->givenManifest([]);

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Aucun paquet', $this->tester->getDisplay());
    }
}
