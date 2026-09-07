<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Runtime;

use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Infrastructure\Runtime\PhpAndSymfonyVersionResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Kernel;

final class PhpAndSymfonyVersionResolverTest extends TestCase
{
    private PhpAndSymfonyVersionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PhpAndSymfonyVersionResolver();
    }

    /**
     * Décision D2 : c'est le moteur qui exécute réellement l'application qui
     * répond, pas une valeur saisie quelque part. Le test est volontairement
     * tautologique sur la valeur — ce qu'il protège, c'est le câblage : que
     * RUNTIME_PHP ne renvoie pas par mégarde la version de Symfony.
     */
    public function testItReportsTheRunningPhpVersion(): void
    {
        $version = $this->resolver->resolve(VersionSource::RUNTIME_PHP);

        self::assertSame(PHP_VERSION, $version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
    }

    public function testItReportsTheLoadedSymfonyVersion(): void
    {
        $version = $this->resolver->resolve(VersionSource::RUNTIME_SYMFONY);

        self::assertSame(Kernel::VERSION, $version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
    }

    /**
     * Une version saisie au backoffice ne concerne pas le résolveur : il répond
     * null, et l'appelant retombe sur la valeur stockée dans l'entité.
     */
    public function testAManualSourceIsNotTheResolversBusiness(): void
    {
        self::assertNull($this->resolver->resolve(VersionSource::MANUAL));
    }
}
