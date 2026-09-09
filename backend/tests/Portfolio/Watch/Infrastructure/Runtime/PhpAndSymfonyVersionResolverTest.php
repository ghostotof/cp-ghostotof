<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Runtime;

use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Infrastructure\Manifest\FileDeployedVersionsReader;
use App\Portfolio\Watch\Infrastructure\Runtime\PhpAndSymfonyVersionResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Kernel;

final class PhpAndSymfonyVersionResolverTest extends TestCase
{
    private PhpAndSymfonyVersionResolver $resolver;
    private string $versionsPath;

    protected function setUp(): void
    {
        $this->versionsPath = sys_get_temp_dir().'/dv-'.bin2hex(random_bytes(6)).'.json';
        $this->resolver = new PhpAndSymfonyVersionResolver(new FileDeployedVersionsReader($this->versionsPath));
    }

    protected function tearDown(): void
    {
        if (is_file($this->versionsPath)) {
            unlink($this->versionsPath);
        }
    }

    /**
     * Décision D2 : c'est le moteur qui exécute réellement l'application qui
     * répond, pas une valeur saisie quelque part. Le test est volontairement
     * tautologique sur la valeur — ce qu'il protège, c'est le câblage : que
     * RUNTIME_PHP ne renvoie pas par mégarde la version de Symfony.
     */
    public function testItReportsTheRunningPhpVersion(): void
    {
        $version = $this->resolver->resolve(VersionSource::RUNTIME_PHP, 'php');

        self::assertSame(PHP_VERSION, $version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
    }

    public function testItReportsTheLoadedSymfonyVersion(): void
    {
        $version = $this->resolver->resolve(VersionSource::RUNTIME_SYMFONY, 'symfony');

        self::assertSame(Kernel::VERSION, $version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
    }

    /**
     * Une version saisie au backoffice ne concerne pas le résolveur : il répond
     * null, et l'appelant retombe sur la valeur stockée dans l'entité.
     */
    public function testAManualSourceIsNotTheResolversBusiness(): void
    {
        self::assertNull($this->resolver->resolve(VersionSource::MANUAL, 'nimporte'));
    }

    /**
     * La source DEPLOYED couvre plusieurs produits : c'est le slug qui choisit,
     * et c'est précisément ce que ce test protège — que postgresql ne reçoive
     * pas la version de rabbitmq.
     */
    public function testADeployedSourceIsResolvedBySlug(): void
    {
        file_put_contents($this->versionsPath, json_encode([
            'versions' => ['postgresql' => '18.4', 'rabbitmq' => '4.3.4'],
        ]));

        self::assertSame('18.4', $this->resolver->resolve(VersionSource::DEPLOYED, 'postgresql'));
        self::assertSame('4.3.4', $this->resolver->resolve(VersionSource::DEPLOYED, 'rabbitmq'));
    }

    /**
     * Relevé absent — le cas normal d'un conteneur de développement où l'image
     * n'a jamais été construite. L'entrée s'affiche alors sans version, ce que
     * la page sait présenter ; faire échouer le rafraîchissement serait hors de
     * proportion.
     */
    public function testAMissingRecordYieldsNoVersionRatherThanAnError(): void
    {
        self::assertNull($this->resolver->resolve(VersionSource::DEPLOYED, 'postgresql'));
    }
}
