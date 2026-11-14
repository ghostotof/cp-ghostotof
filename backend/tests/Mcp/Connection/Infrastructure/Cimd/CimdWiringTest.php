<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetcherInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Infrastructure\Cimd\HostAddressResolverInterface;
use App\Mcp\Connection\Infrastructure\Cimd\HttpClientMetadataFetcher;
use App\Mcp\Connection\Infrastructure\Cimd\NativeHostAddressResolver;
use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    /**
     * La couche 5 juge avec la liste de la couche 2, non avec celle de Symfony
     * (qui omet le multicast et les blocs d'affectations de protocoles).
     *
     * Ce que l'assertion prouve : la configuration. Elle lit la liste que le
     * service du conteneur détient (propriété privée du décorateur, par
     * réflexion) : le client réel ne peut pas être exercé sans réseau, et un
     * essai contre une adresse littérale tente une vraie connexion. Le
     * comportement de cette liste est prouvé par
     * `HttpClientMetadataFetcherTest::testAConnectionThatLandsOnARangeOfThePolicyIsRefused`
     * (`primary_ip` d'une `MockResponse`). Ce qu'elle ne prouve pas : que
     * Symfony lise toujours cette propriété comme on l'attend.
     */
    public function testTheMetadataHttpClientJudgesWithThePublicAddressPolicyList(): void
    {
        $subnets = new ReflectionProperty(NoPrivateNetworkHttpClient::class, 'subnets')
            ->getValue($this->service('app.mcp.cimd.http_client'));

        self::assertNotNull($subnets, 'sans liste, Symfony retombe sur sa liste par défaut');
        self::assertSame(PublicAddressPolicy::SUBNETS, $subnets);
    }

    /**
     * Le transport est celui du service seul, non le `http_client` partagé :
     * avec curl, `resolve` s'écrit dans le cache DNS du multi-handle partagé
     * (`$multi->dnsCache->hostnames`) et les connexions sont réutilisées,
     * d'un appel à l'autre et d'un service à l'autre. Un transport propre en
     * garde l'effet pour ce seul service.
     *
     * Ce que l'assertion prouve : l'objet décoré n'est pas celui du client
     * partagé (identité, par réflexion sur la propriété privée du décorateur).
     * Ce qu'elle ne prouve pas : l'absence de tout état partagé plus bas (le
     * `MockHttpClient` des autres tests ne peut pas décorer une fabrique), ni
     * le cache DNS lui-même, propre à curl.
     */
    public function testTheMetadataHttpClientHasItsOwnTransport(): void
    {
        $inner = new ReflectionProperty(NoPrivateNetworkHttpClient::class, 'client')
            ->getValue($this->service('app.mcp.cimd.http_client'));

        self::assertInstanceOf(HttpClientInterface::class, $inner);
        self::assertNotSame(
            self::getContainer()->get('http_client'),
            $inner,
            'le client du document ne décore pas le client partagé du framework',
        );
    }

    /**
     * Le transport est toujours curl, jamais le transport natif de PHP : le
     * fetcher compte sur `CURLOPT_RESOLVE` (l'épinglage de l'adresse vérifiée)
     * et sur `max_connect_duration` (`CURLOPT_CONNECTTIMEOUT_MS`). Le transport
     * natif plafonne toute lecture de socket à `max_connect_duration`
     * (`NativeHttpClient`, 1 s au lieu des 2 s de `timeout`) : une fabrique qui
     * y retomberait dégraderait la borne en silence.
     *
     * Ce que l'assertion prouve : l'objet que le décorateur enveloppe (propriété
     * privée, par réflexion) est de la classe `CurlHttpClient`. Ce qu'elle ne
     * prouve pas : le comportement de curl, qu'aucun test ne joint sans réseau.
     */
    public function testTheMetadataHttpClientAlwaysSpeaksCurl(): void
    {
        $inner = new ReflectionProperty(NoPrivateNetworkHttpClient::class, 'client')
            ->getValue($this->service('app.mcp.cimd.http_client'));

        self::assertInstanceOf(CurlHttpClient::class, $inner);
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
