<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use App\Mcp\Connection\Infrastructure\Cimd\HttpClientMetadataFetcher;
use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use App\Tests\Mcp\Connection\Support\FixedHostAddressResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * La récupération du document d'un client CIMD (#651, tâche 3) : l'hôte du
 * `client_id` est résolu par le résolveur (une doublure : aucun DNS en test),
 * chaque adresse est jugée par {@see PublicAddressPolicy}, puis la requête part
 * vers la première adresse, épinglée par l'option `resolve` : le client HTTP ne
 * résout rien lui-même, et ce qu'on a vérifié est ce qu'on contacte.
 *
 * Le client testé est le décorateur réel de Symfony autour d'un
 * {@see MockHttpClient} : la dernière couche (`NoPrivateNetworkHttpClient`,
 * qui juge l'adresse effectivement jointe) est exercée telle quelle.
 */
final class HttpClientMetadataFetcherTest extends TestCase
{
    private const string CLIENT_ID = 'https://claude.ai/oauth/claude-code-client-metadata';

    /**
     * Le document publié par Claude Code, tel qu'il est servi.
     */
    private const string DOCUMENT = '{"client_id":"https://claude.ai/oauth/claude-code-client-metadata","client_name":"Claude Code","client_uri":"https://claude.ai","redirect_uris":["http://localhost/callback","http://127.0.0.1/callback"],"grant_types":["authorization_code","refresh_token"],"response_types":["code"],"token_endpoint_auth_method":"none"}';

    /**
     * Le `User-Agent` des autres appels sortants du site
     * ({@see \App\Portfolio\Watch\Infrastructure\Http\EndOfLifeDateClient}).
     */
    private const string USER_AGENT = 'cp-ghostotof (+https://github.com/ghostotof/cp-ghostotof)';

    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $requests = [];

    public function testTheRequestGoesToTheVerifiedAddress(): void
    {
        $resolver = new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]);

        $fetched = $this->fetcher($resolver, $this->documentResponse())->fetch($this->clientId());

        self::assertSame(self::DOCUMENT, $fetched->body);
        self::assertNull($fetched->maxAge, 'la lecture du max-age est la tâche 4');
        self::assertSame(['claude.ai'], $resolver->resolvedHosts());
        self::assertCount(1, $this->requests);

        $request = $this->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame(self::CLIENT_ID, $request['url']);

        $options = $request['options'];
        self::assertSame(['claude.ai' => '1.1.1.1'], $options['resolve'], 'l\'adresse vérifiée est épinglée');
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(HttpClientMetadataFetcher::TIMEOUT, $options['timeout']);
        self::assertSame(2.0, $options['timeout']);
        self::assertSame(HttpClientMetadataFetcher::MAX_DURATION, $options['max_duration']);
        self::assertSame(3.0, $options['max_duration']);
        self::assertIsArray($options['normalized_headers']);
        self::assertSame(['Accept: application/json'], $options['normalized_headers']['accept']);
        self::assertSame(['User-Agent: '.self::USER_AGENT], $options['normalized_headers']['user-agent']);
    }

    public function testAHostThatDoesNotResolveIsRefusedAsTransient(): void
    {
        $refusal = $this->refusalOf(new FixedHostAddressResolver());

        self::assertSame(ClientMetadataRefusal::Unresolvable, $refusal);
        self::assertTrue($refusal->isTransient());
        self::assertSame([], $this->requests);
    }

    #[DataProvider('specialAddresses')]
    public function testAHostResolvingToASpecialAddressIsRefused(string $address): void
    {
        $refusal = $this->refusalOf(new FixedHostAddressResolver(['claude.ai' => [$address]]));

        self::assertSame(ClientMetadataRefusal::AddressBlocked, $refusal);
        self::assertFalse($refusal->isTransient());
        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function specialAddresses(): iterable
    {
        yield 'bouclage' => ['127.0.0.1'];
        yield 'réseau privé (10/8)' => ['10.0.0.5'];
        yield 'métadonnées du nuage' => ['169.254.169.254'];
        yield 'réseau privé (192.168/16)' => ['192.168.0.1'];
        yield 'multidiffusion' => ['224.0.0.1'];
    }

    /**
     * Review Focus 1 : une réponse DNS mixte. Épingler la seule adresse
     * publique ne suffirait pas — une autre requête, ou un autre résolveur,
     * prendrait l'adresse privée : l'hôte entier est refusé.
     */
    public function testAHostWithOnePrivateAddressAmongPublicOnesIsRefused(): void
    {
        $refusal = $this->refusalOf(new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1', '10.0.0.5']]));

        self::assertSame(ClientMetadataRefusal::AddressBlocked, $refusal);
        self::assertSame([], $this->requests);
    }

    /**
     * La couche 5 tient seule : même si l'adresse épinglée était contournée,
     * une connexion qui aboutit sur une adresse privée est coupée par
     * `NoPrivateNetworkHttpClient`, ce que la récupération range en `Unreachable`.
     */
    public function testAConnectionThatLandsOnAPrivateAddressIsRefused(): void
    {
        $response = new MockResponse(self::DOCUMENT, [
            'primary_ip' => '127.0.0.1',
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $refusal = $this->refusalOf(new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]), $response);

        self::assertSame(ClientMetadataRefusal::Unreachable, $refusal);
        self::assertTrue($refusal->isTransient());
    }

    private function refusalOf(FixedHostAddressResolver $resolver, ?MockResponse $response = null): ClientMetadataRefusal
    {
        try {
            $this->fetcher($resolver, $response ?? $this->documentResponse())->fetch($this->clientId());
        } catch (ClientMetadataRefusedException $refused) {
            self::assertSame($refused->refusal->value, $refused->getMessage(), 'le message ne porte que la cause');

            return $refused->refusal;
        }

        self::fail('La récupération devait être refusée.');
    }

    private function fetcher(FixedHostAddressResolver $resolver, MockResponse $response): HttpClientMetadataFetcher
    {
        $client = new NoPrivateNetworkHttpClient(new MockHttpClient(
            function (string $method, string $url, array $options) use ($response): ResponseInterface {
                /** @var array<string, mixed> $options */
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return $response;
            },
        ));

        return new HttpClientMetadataFetcher($client, $resolver, new PublicAddressPolicy());
    }

    private function documentResponse(): MockResponse
    {
        return new MockResponse(self::DOCUMENT, [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function clientId(): CimdClientId
    {
        $clientId = CimdClientId::tryFrom(self::CLIENT_ID);
        self::assertNotNull($clientId);

        return $clientId;
    }
}
