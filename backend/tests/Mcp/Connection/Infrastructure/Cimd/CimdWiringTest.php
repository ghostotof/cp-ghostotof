<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La liste des domaines dont un client CIMD est admis vient de la
 * configuration (#651) : le paramètre versionné `app.mcp.cimd.allowed_domains`
 * de `config/services.yaml`, le même dans tous les environnements, et le
 * conteneur en construit AllowedClientDomains — aucune constante n'en garde
 * une copie.
 */
final class CimdWiringTest extends KernelTestCase
{
    public function testTheAllowedDomainsAreTheVersionedParameter(): void
    {
        self::assertSame(
            ['claude.ai', 'chatgpt.com'],
            self::getContainer()->getParameter('app.mcp.cimd.allowed_domains'),
        );
    }

    public function testTheContainerBuildsTheAllowedDomainsFromTheParameter(): void
    {
        $domains = self::getContainer()->get(AllowedClientDomains::class);

        $clientId = CimdClientId::tryFrom('https://claude.ai/oauth/claude-code-client-metadata');
        self::assertNotNull($clientId);

        self::assertTrue($domains->allows($clientId));
    }
}
