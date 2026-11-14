<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use App\Mcp\Connection\Infrastructure\Cimd\HttpClientMetadataFetcher;
use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use App\Tests\Mcp\Connection\Support\FixedHostAddressResolver;
use App\Tests\Mcp\Connection\Support\LastResponseHttpClient;
use Generator;
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

    private ?LastResponseHttpClient $client = null;

    public function testTheRequestGoesToTheVerifiedAddress(): void
    {
        $resolver = new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]);

        $fetched = $this->fetcher($resolver, $this->documentResponse())->fetch($this->clientId());

        self::assertSame(self::DOCUMENT, $fetched->body);
        self::assertNull($fetched->maxAge, 'sans Cache-Control, aucune durée n\'est annoncée');
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
        self::assertSame(HttpClientMetadataFetcher::MAX_CONNECT_DURATION, $options['max_connect_duration']);
        self::assertSame(1.0, $options['max_connect_duration'], 'la connexion elle-même est bornée');
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

    /**
     * La couche 5 juge avec la même liste que la couche 2
     * ({@see PublicAddressPolicy::SUBNETS}) : la liste par défaut de Symfony
     * ne couvre ni le multicast ni les blocs d'affectations de protocoles, une
     * connexion qui y aboutirait passerait. `primary_ip` est l'adresse où la
     * connexion a abouti, fixée par la `MockResponse` : aucun réseau.
     */
    #[DataProvider('landingAddressesOfRangesSymfonyDoesNotList')]
    public function testAConnectionThatLandsOnARangeOfThePolicyIsRefused(string $landing): void
    {
        $response = new MockResponse(self::DOCUMENT, [
            'primary_ip' => $landing,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        self::assertSame(ClientMetadataRefusal::Unreachable, $this->refusalOf($this->publicHost(), $response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function landingAddressesOfRangesSymfonyDoesNotList(): iterable
    {
        yield 'multicast IPv4' => ['224.0.0.1'];
        yield 'affectations de protocoles IPv4' => ['192.0.0.1'];
        yield 'relais 6to4' => ['192.88.99.1'];
        yield 'multicast IPv6' => ['ff02::1'];
        yield 'préfixe factice IPv6' => ['100:0:0:1::1'];
    }

    #[DataProvider('cacheControls')]
    public function testAPublishedDocumentIsReturnedWithItsMaxAge(?string $cacheControl, ?int $maxAge): void
    {
        $headers = ['content-type' => 'application/json'];
        if (null !== $cacheControl) {
            $headers['cache-control'] = $cacheControl;
        }

        $fetched = $this->fetcher($this->publicHost(), $this->response(self::DOCUMENT, 200, $headers))->fetch($this->clientId());

        self::assertSame(self::DOCUMENT, $fetched->body);
        self::assertSame($maxAge, $fetched->maxAge);
    }

    /**
     * @return iterable<string, array{?string, ?int}>
     */
    public static function cacheControls(): iterable
    {
        yield 'max-age publié' => ['public, max-age=300', 300];
        yield 'max-age seul' => ['max-age=60', 60];
        yield 'majuscules' => ['Public, Max-Age=120', 120];
        yield 'max-age nul' => ['max-age=0', 0];
        yield 'aucun en-tête' => [null, null];
        yield 'no-store' => ['no-store', null];
        yield 'max-age illisible' => ['max-age=abc', null];
        yield 'max-age négatif' => ['max-age=-5', null];
        yield 'max-age démesuré' => ['max-age=99999999999999999999', null];
        yield 's-maxage n\'est pas max-age' => ['s-maxage=300', null];
    }

    #[DataProvider('redirectStatuses')]
    public function testARedirectionIsRefusedAndNeverFollowed(int $status): void
    {
        $response = $this->response('', $status, ['location' => 'http://169.254.169.254/latest/meta-data/']);

        $refusal = $this->refusalOf($this->publicHost(), $response);

        self::assertSame(ClientMetadataRefusal::Redirected, $refusal);
        self::assertFalse($refusal->isTransient());
        self::assertCount(1, $this->requests, 'la cible de la redirection n\'est jamais demandée');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function redirectStatuses(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('refusedStatuses')]
    public function testAnyStatusOtherThan200IsRefused(int $status, ClientMetadataRefusal $expected): void
    {
        $refusal = $this->refusalOf($this->publicHost(), $this->response(self::DOCUMENT, $status));

        self::assertSame($expected, $refusal);
        self::assertSame($expected->isTransient(), ClientMetadataRefusal::UpstreamError === $expected);
    }

    /**
     * @return iterable<string, array{int, ClientMetadataRefusal}>
     */
    public static function refusedStatuses(): iterable
    {
        yield '204 sans contenu' => [204, ClientMetadataRefusal::UnexpectedStatus];
        yield '206 partiel' => [206, ClientMetadataRefusal::UnexpectedStatus];
        yield '401' => [401, ClientMetadataRefusal::UnexpectedStatus];
        yield '404' => [404, ClientMetadataRefusal::UnexpectedStatus];
        yield '429 trop de requêtes' => [429, ClientMetadataRefusal::UpstreamError];
        yield '500' => [500, ClientMetadataRefusal::UpstreamError];
        yield '503' => [503, ClientMetadataRefusal::UpstreamError];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('wrongContentTypes')]
    public function testAnUnexpectedContentTypeIsRefused(array $headers): void
    {
        $refusal = $this->refusalOf($this->publicHost(), $this->response(self::DOCUMENT, 200, $headers));

        self::assertSame(ClientMetadataRefusal::UnexpectedContentType, $refusal);
        self::assertFalse($refusal->isTransient());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function wrongContentTypes(): iterable
    {
        yield 'html' => [['content-type' => 'text/html']];
        yield 'en-tête absent' => [[]];
        yield 'jsonp' => [['content-type' => 'application/jsonp']];
        yield 'préfixe seulement' => [['content-type' => 'application/json-seq']];
        yield 'json en texte' => [['content-type' => 'text/json']];
    }

    /**
     * Bombe de décompression : 5 120 octets bornent le corps reçu, pas le corps
     * décompressé. Un `Accept-Encoding` explicite est ce qui désactive
     * l'inflate automatique de Symfony HttpClient (curl comme natif) : sans lui,
     * un document gzip de quelques Ko serait rendu en plusieurs Go, un morceau
     * à la fois mais sans borne de mémoire sur le chunk.
     */
    public function testTheRequestAsksForNoCompression(): void
    {
        $this->fetcher($this->publicHost(), $this->documentResponse())->fetch($this->clientId());

        $options = $this->requests[0]['options'];
        self::assertIsArray($options['normalized_headers']);
        self::assertSame(['Accept-Encoding: identity'], $options['normalized_headers']['accept-encoding']);
    }

    /**
     * Le client HTTP lit `https_proxy` / `all_proxy` dans l'environnement du
     * processus : derrière un proxy, la connexion irait au proxy, qui résout le
     * nom lui-même, et l'adresse épinglée ne serait plus celle contactée.
     * `no_proxy: *` écarte le proxy de l'environnement (curl et natif).
     */
    public function testTheRequestNeverGoesThroughAnEnvironmentProxy(): void
    {
        $this->fetcher($this->publicHost(), $this->documentResponse())->fetch($this->clientId());

        self::assertSame('*', $this->requests[0]['options']['no_proxy']);
    }

    /**
     * Un hôte qui compresse malgré `identity` est refusé avant toute lecture :
     * la connexion est fermée, rien n'est décompressé. Chaque ligne d'en-tête et
     * chaque valeur d'une liste séparée par une virgule est contrôlée : `identity`
     * suivi de `gzip`, sur deux lignes ou sur une, est refusé comme `gzip` seul.
     *
     * @param string|list<string> $encoding une ligne, ou plusieurs lignes `Content-Encoding`
     */
    #[DataProvider('compressedEncodings')]
    public function testACompressedResponseIsRefusedAndTheConnectionClosed(string|array $encoding): void
    {
        $response = $this->response(self::DOCUMENT, 200, [
            'content-type' => 'application/json',
            'content-encoding' => $encoding,
        ]);

        $refusal = $this->refusalOf($this->publicHost(), $response);

        self::assertSame(ClientMetadataRefusal::UnexpectedContentType, $refusal);
        self::assertFalse($refusal->isTransient());
        self::assertTrue($this->client?->lastResponse()->getInfo('canceled'), 'la connexion est fermée');
    }

    /**
     * @return iterable<string, array{string|list<string>}>
     */
    public static function compressedEncodings(): iterable
    {
        yield 'gzip' => ['gzip'];
        yield 'casse et espaces' => ['  GZip '];
        yield 'deflate' => ['deflate'];
        yield 'brotli' => ['br'];
        yield 'chaîne' => ['gzip, identity'];
        yield 'chaîne, identity d\'abord' => ['identity, gzip'];
        yield 'deux lignes, identity d\'abord' => [['identity', 'gzip']];
        yield 'deux lignes, identity ensuite' => [['gzip', 'identity']];
        yield 'deux lignes, liste sur la seconde' => [['identity', ' Identity , BR ']];
    }

    /**
     * @param string|list<string> $encoding
     */
    #[DataProvider('identityEncodings')]
    public function testAnIdentityEncodedResponseIsAccepted(string|array $encoding): void
    {
        $response = $this->response(self::DOCUMENT, 200, [
            'content-type' => 'application/json',
            'content-encoding' => $encoding,
        ]);

        $fetched = $this->fetcher($this->publicHost(), $response)->fetch($this->clientId());

        self::assertSame(self::DOCUMENT, $fetched->body);
    }

    /**
     * @return iterable<string, array{string|list<string>}>
     */
    public static function identityEncodings(): iterable
    {
        yield 'identity' => ['identity'];
        yield 'casse et espaces' => ['  Identity '];
        yield 'liste de identity' => ['identity, Identity'];
        yield 'deux lignes identity' => [['identity', 'IDENTITY']];
    }

    #[DataProvider('acceptedContentTypes')]
    public function testAJsonContentTypeIsAcceptedWhateverItsParametersAndCase(string $contentType): void
    {
        $fetched = $this->fetcher($this->publicHost(), $this->response(self::DOCUMENT, 200, ['content-type' => $contentType]))->fetch($this->clientId());

        self::assertSame(self::DOCUMENT, $fetched->body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedContentTypes(): iterable
    {
        yield 'simple' => ['application/json'];
        yield 'avec charset' => ['application/json; charset=utf-8'];
        yield 'casse' => ['Application/JSON'];
        yield 'espaces' => ['  application/json ;charset=UTF-8'];
    }

    /**
     * Le document annonce une taille au-delà de la borne : refus sur l'en-tête,
     * la connexion est fermée.
     *
     * Non prouvable ici : que rien n'est lu. `MockResponse` produit tout le
     * corps d'un coup à la première lecture, quoi qu'on fasse ensuite.
     */
    public function testAnAnnouncedOversizedBodyIsRefusedAndTheConnectionClosed(): void
    {
        $body = str_repeat('a', HttpClientMetadataFetcher::MAX_BYTES + 1);
        $response = $this->response($body, 200, [
            'content-type' => 'application/json',
            'content-length' => (string) \strlen($body),
        ]);

        $refusal = $this->refusalOf($this->publicHost(), $response);

        self::assertSame(ClientMetadataRefusal::TooLarge, $refusal);
        self::assertFalse($refusal->isTransient());
        self::assertTrue($this->client?->lastResponse()->getInfo('canceled'), 'la connexion est fermée');
    }

    /**
     * Aucune taille annoncée : c'est le cumul des morceaux reçus qui borne la
     * lecture, et la connexion est fermée dès qu'il dépasse.
     */
    public function testAnUnannouncedOversizedBodyStopsAtTheBound(): void
    {
        $chunks = (static function (): Generator {
            for ($i = 0; $i < 100; ++$i) {
                yield str_repeat('a', 1024);
            }
        })();

        $refusal = $this->refusalOf($this->publicHost(), $this->response($chunks, 200, ['content-type' => 'application/json']));

        self::assertSame(ClientMetadataRefusal::TooLarge, $refusal);
        self::assertTrue($this->client?->lastResponse()->getInfo('canceled'), 'la connexion est fermée');
    }

    public function testABodyOfExactlyTheBoundIsAccepted(): void
    {
        $body = str_repeat('a', HttpClientMetadataFetcher::MAX_BYTES);
        $response = $this->response($body, 200, [
            'content-type' => 'application/json',
            'content-length' => (string) \strlen($body),
        ]);

        $fetched = $this->fetcher($this->publicHost(), $response)->fetch($this->clientId());

        self::assertSame($body, $fetched->body);
    }

    /**
     * Un serveur qui envoie un octet puis se tait : le délai d'inactivité
     * coupe, c'est un refus transitoire (le serveur peut répondre demain).
     */
    public function testAStalledBodyIsRefusedAsTransient(): void
    {
        $stalled = (static function (): Generator {
            yield 'x';
            yield '';
        })();

        $refusal = $this->refusalOf($this->publicHost(), $this->response($stalled, 200, ['content-type' => 'application/json']));

        self::assertSame(ClientMetadataRefusal::Unreachable, $refusal);
        self::assertTrue($refusal->isTransient());
        self::assertTrue($this->client?->lastResponse()->getInfo('canceled'), 'la connexion est fermée');
    }

    public function testATransportFailureIsRefusedAsTransient(): void
    {
        $refusal = $this->refusalOf($this->publicHost(), new MockResponse('', ['error' => 'refused']));

        self::assertSame(ClientMetadataRefusal::Unreachable, $refusal);
        self::assertTrue($refusal->isTransient());
    }

    /**
     * Ce que le serveur envoie (corps, `Location`, type) n'entre jamais dans
     * le message d'un refus, ni dans celui de son exception précédente : le
     * message finit dans les journaux.
     *
     * Le message d'une erreur de transport, lui, reste chaîné en précédente
     * (décision de la tâche 3, voir le docblock du récupérateur) : il n'est pas
     * ce que l'hôte a choisi d'envoyer, mais un texte du client HTTP.
     */
    public function testARefusalNeverCarriesWhatTheServerSent(): void
    {
        $marker = 'JETON-'.bin2hex(random_bytes(8));
        $cases = [
            $this->response($marker, 301, ['location' => 'http://'.$marker.'.example/']),
            $this->response($marker, 404, ['content-type' => 'text/'.$marker]),
            $this->response($marker, 200, ['content-type' => 'text/'.$marker]),
            $this->response(str_repeat($marker, 600), 200, ['content-type' => 'application/json']),
        ];

        foreach ($cases as $response) {
            try {
                $this->fetcher($this->publicHost(), $response)->fetch($this->clientId());
                self::fail('La récupération devait être refusée.');
            } catch (ClientMetadataRefusedException $refused) {
                self::assertStringNotContainsString($marker, $refused->getMessage());
                for ($previous = $refused->getPrevious(); null !== $previous; $previous = $previous->getPrevious()) {
                    self::assertStringNotContainsString($marker, $previous->getMessage());
                }
            }
        }
    }

    public function testAHostOutsideTheDomainListIsRefusedBeforeAnyResolution(): void
    {
        // Un hôte public et joignable, mais que la liste n'admet pas : le
        // récupérateur ne le résout pas et ne lui envoie rien (couche 1).
        $resolver = new FixedHostAddressResolver(['evil.example' => ['1.1.1.1']]);
        $clientId = CimdClientId::tryFrom('https://evil.example/oauth/metadata');
        self::assertNotNull($clientId);

        try {
            $this->fetcher($resolver, $this->documentResponse())->fetch($clientId);
            self::fail('La récupération devait être refusée.');
        } catch (ClientMetadataRefusedException $refused) {
            self::assertSame(ClientMetadataRefusal::DomainNotAllowed, $refused->refusal);
        }

        self::assertSame([], $resolver->resolvedHosts(), 'aucune résolution pour un hôte hors liste');
        self::assertSame([], $this->requests, 'aucune requête pour un hôte hors liste');
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
        // La couche 5 comme `services.yaml` la câble : la liste de la politique.
        $this->client = $client = new LastResponseHttpClient(new NoPrivateNetworkHttpClient(new MockHttpClient(
            function (string $method, string $url, array $options) use ($response): ResponseInterface {
                /** @var array<string, mixed> $options */
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return $response;
            },
        ), PublicAddressPolicy::SUBNETS));

        return new HttpClientMetadataFetcher($client, $resolver, new PublicAddressPolicy(), AllowedClientDomains::fromList(['claude.ai']));
    }

    private function publicHost(): FixedHostAddressResolver
    {
        return new FixedHostAddressResolver(['claude.ai' => ['1.1.1.1']]);
    }

    /**
     * @param string|iterable<string> $body
     * @param array<string, string>   $headers
     */
    private function response(string|iterable $body, int $status, array $headers = []): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
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
