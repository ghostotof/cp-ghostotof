<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetcherInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Infrastructure\Cimd\HostAddressResolverInterface;
use App\Mcp\Connection\Infrastructure\Cimd\HttpClientMetadataFetcher;
use App\Mcp\Connection\Infrastructure\Cimd\NativeHostAddressResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

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

    /**
     * La couche 5 (#651, D-D) : le client du document ne joint jamais une
     * adresse privée, quoi que la résolution ait rendu.
     */
    public function testTheMetadataHttpClientRefusesPrivateNetworks(): void
    {
        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, $this->service('app.mcp.cimd.http_client'));
    }

    public function testTheFetcherIsTheHttpImplementation(): void
    {
        self::assertInstanceOf(HttpClientMetadataFetcher::class, $this->service(ClientMetadataFetcherInterface::class));
    }

    public function testTheResolverIsTheNativeOne(): void
    {
        self::assertInstanceOf(NativeHostAddressResolver::class, $this->service(HostAddressResolverInterface::class));
    }

    /**
     * Le service tel que le conteneur le sert, typé `object` : l'extension
     * PHPStan de Symfony connaît la classe d'un service dont l'identifiant est
     * une constante, et verrait dans l'assertion une vérité toujours vraie,
     * alors que c'est elle qui prouve le câblage.
     *
     * @param non-empty-string $id
     */
    private function service(string $id): object
    {
        return self::getContainer()->get($id);
    }
}
