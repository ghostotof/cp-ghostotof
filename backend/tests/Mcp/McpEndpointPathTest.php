<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\Infrastructure\Http\McpEndpoint;
use App\Tests\Support\RepositoryFiles;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le chemin du serveur MCP a une source, {@see McpEndpoint::PATH} (#673) : le
 * bundle le lit (`mcp.yaml`, `!php/const`), les écouteurs du contexte et la
 * garde CSRF le nomment par la constante. Ce que PHP ne peut pas lire — le
 * pattern du firewall, la location nginx, la constante du front — est
 * comparé ici : changer le chemin d'un côté seulement fait rougir ce test.
 *
 * Pas lus, acceptés : la conf nginx de dev, miroir de la location k8s que
 * `tools/check-backend-nginx-rate-limits.sh` vérifie directive par directive
 * (`MIRRORED_LOCATIONS`), et les scripts du smoke préprod (`tools/`, non
 * monté dans le conteneur de dev), qu'un chemin changé fait échouer à la
 * première préprod.
 */
final class McpEndpointPathTest extends KernelTestCase
{
    public function testTheBundleServesTheServerOnThePath(): void
    {
        $route = self::getContainer()->get('router')->getRouteCollection()->get('_mcp_endpoint_portfolio');
        self::assertNotNull($route, 'La route du serveur MCP a changé de nom : adapter ce test.');
        self::assertSame(McpEndpoint::PATH, $route->getPath());
    }

    public function testTheMcpFirewallCoversThePathAndNothingBeside(): void
    {
        $map = self::getContainer()->get('security.firewall.map');

        self::assertSame('mcp', $map->getFirewallConfig(Request::create(McpEndpoint::PATH))?->getName());
        self::assertSame('api', $map->getFirewallConfig(Request::create(McpEndpoint::PATH.'x'))?->getName());
    }

    public function testTheSidecarHasALocationForExactlyThePath(): void
    {
        $conf = RepositoryFiles::read(RepositoryFiles::repository('k8s/base/backend-nginx.conf'));

        self::assertSame(1, substr_count($conf, \sprintf('location = %s {', McpEndpoint::PATH)), 'backend-nginx.conf : la location exacte du serveur MCP manque ou est en double.');
    }

    public function testTheFrontendPublishesThePath(): void
    {
        $configuration = RepositoryFiles::read(RepositoryFiles::repository('frontend/src/domain/mcp/services/mcpClientConfiguration.ts'));

        self::assertSame(1, preg_match("/export const MCP_ENDPOINT_PATH = '([^']*)'/", $configuration, $match), 'mcpClientConfiguration.ts : MCP_ENDPOINT_PATH introuvable.');
        self::assertSame(McpEndpoint::PATH, $match[1]);
    }
}
