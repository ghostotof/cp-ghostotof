<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\ApiPlatform;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Mcp\Infrastructure\ApiPlatform\RouteAboveMcpTiersException;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\AnonymousCvSectionResource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le lecteur appelle le provider directement, sans passer par le firewall :
 * c'est donc lui qui refuse une route qu'`access_control` réserve à un palier
 * supérieur au palier de base (ADR 0006 D2 : sans connexion MCP, le serveur
 * sert les paliers anonyme et de base, rien de plus). Le test symétrique ne
 * doit pas être la seule barrière.
 */
final class ApiPlatformRoutePayloadReaderTest extends KernelTestCase
{
    public function testARouteReservedAboveTheBaseTierIsRefused(): void
    {
        self::bootKernel();

        $this->expectException(RouteAboveMcpTiersException::class);

        self::getContainer()->get(RoutePayloadReaderInterface::class)->read(BackofficeAboutSettingsResource::class, ['locale' => 'fr']);
    }

    public function testABaseTierRouteIsRead(): void
    {
        self::bootKernel();

        $payload = self::getContainer()->get(RoutePayloadReaderInterface::class)->read(AnonymousCvSectionResource::class, ['locale' => 'fr']);

        self::assertSame([], $payload);
    }
}
