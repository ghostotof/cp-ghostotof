<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le point d'autorisation OAuth de la connexion MCP (#647, spec 0007 phase 2),
 * `GET /api/oauth/authorize`, de bout en bout par HTTP.
 *
 * Trois issues, et jamais une autre :
 * - une demande valide est renvoyée, telle quelle, à la page de consentement
 *   du frontend, dans la langue de la personne ;
 * - un refus dont la redirection a été validée revient au client, avec
 *   `error`, `state` et `iss` (RFC 9207) ;
 * - un refus sans redirection validée (client inconnu, redirection non
 *   enregistrée, demande malformée) est un JSON de league (RFC 6749), sans
 *   `Location` : on ne redirige jamais vers une URI que rien n'a validée.
 *
 * Les URL attendues sont recalculées depuis les paramètres de configuration,
 * jamais lues dans `OAuthEndpoints` : le test resterait vert sur une URL fausse
 * qu'il recopierait.
 */
final class AuthorizeControllerTest extends WebTestCase
{
    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string PATH = '/api/oauth/authorize';

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    private const string LOOPBACK_REDIRECT = 'http://localhost:49152/callback';

    /** Un vérificateur PKCE conforme à RFC 7636 (43 caractères au moins). */
    private const string CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-test';

    /** La borne de `state`, en caractères (VSCHAR, RFC 6749 annexe A.5 : un octet chacun). */
    private const int STATE_MAX_LENGTH = 1024;

    /** Les paramètres que le point d'autorisation lit, chacun une chaîne. */
    private const array EXPECTED_PARAMETERS = ['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->purge();
        self::getContainer()->get(McpClientRepositoryInterface::class)->add(
            McpClient::register(self::CLIENT_ID, 'Client de test', ['http://localhost/callback', 'https://client.example.test/callback'], new DateTimeImmutable()),
        );
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * La demande valide part vers la page de consentement, sa requête
     * d'origine reprise telle quelle : la page la renverra au serveur, qui la
     * revalidera. Français par défaut.
     */
    public function testAValidRequestIsSentToTheConsentPageWithItsQueryAsReceived(): void
    {
        $query = http_build_query($this->validParameters());

        $response = $this->authorize($query);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame($this->frontendBase().'/fr/oauth/consent?'.$query, $response->headers->get('Location'));
        $this->assertNotStored($response);
    }

    /**
     * La langue retenue est l'une des deux du site, jamais une valeur de
     * l'en-tête recopiée dans l'URL.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function acceptLanguages(): iterable
    {
        yield 'anglais' => ['en', 'en'];
        yield 'anglais régional' => ['en-GB,en;q=0.9', 'en'];
        yield 'français' => ['fr-FR', 'fr'];
        yield 'langue non servie' => ['de', 'fr'];
        yield 'valeur hostile' => ['../../evil', 'fr'];
    }

    #[DataProvider('acceptLanguages')]
    public function testTheConsentPageIsInTheVisitorsLanguageAmongTheSitesTwo(string $acceptLanguage, string $locale): void
    {
        $query = http_build_query($this->validParameters());

        $response = $this->authorize($query, ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);

        self::assertSame($this->frontendBase().'/'.$locale.'/oauth/consent?'.$query, $response->headers->get('Location'));
    }

    /**
     * PKCE `S256` seul, prouvé par HTTP (le grant en décide) : le refus revient
     * au client sur sa redirection validée, `state` et `iss` compris.
     *
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function refusedPkce(): iterable
    {
        yield 'aucun défi' => [['code_challenge' => null, 'code_challenge_method' => null]];
        yield 'plain' => [['code_challenge_method' => 'plain']];
        yield 'défi sans méthode' => [['code_challenge_method' => null]];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('refusedPkce')]
    public function testARequestWithoutS256PkceIsRefusedBackToTheClient(array $overrides): void
    {
        $response = $this->authorize(http_build_query($this->validParameters($overrides)));

        $this->assertRefusedBackToTheClient($response, 'invalid_request');
    }

    /**
     * RFC 8707 : la ressource est exigée, et c'est le serveur MCP du site.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function refusedResources(): iterable
    {
        yield 'absente' => [null];
        yield 'étrangère' => ['https://other.example.test/api/mcp'];
        yield 'vide' => [''];
        yield 'barre finale' => ['__RESOURCE__/'];
    }

    #[DataProvider('refusedResources')]
    public function testAMissingOrForeignResourceIsAnInvalidTargetBackToTheClient(?string $resource): void
    {
        $resource = null === $resource ? null : str_replace('__RESOURCE__', $this->resource(), $resource);

        $response = $this->authorize(http_build_query($this->validParameters(['resource' => $resource])));

        $this->assertRefusedBackToTheClient($response, 'invalid_target');
    }

    public function testAnUnknownScopeIsAnInvalidScopeBackToTheClient(): void
    {
        $response = $this->authorize(http_build_query($this->validParameters(['scope' => 'nominative:write'])));

        $this->assertRefusedBackToTheClient($response, 'invalid_scope');
    }

    /**
     * Le port seul est libre sur la boucle locale (RFC 8252 §7.3).
     *
     * @return iterable<string, array{string}>
     */
    public static function acceptedRedirects(): iterable
    {
        yield 'boucle locale sans port' => ['http://localhost/callback'];
        yield 'boucle locale, port éphémère' => ['http://localhost:49152/callback'];
        yield 'boucle locale, autre port' => ['http://localhost:1/callback'];
        yield 'https exacte' => ['https://client.example.test/callback'];
    }

    #[DataProvider('acceptedRedirects')]
    public function testARegisteredRedirectIsAccepted(string $redirectUri): void
    {
        $query = http_build_query($this->validParameters(['redirect_uri' => $redirectUri]));

        $response = $this->authorize($query);

        self::assertSame($this->frontendBase().'/fr/oauth/consent?'.$query, $response->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function refusedClients(): iterable
    {
        yield 'client inconnu' => [['client_id' => 'https://unknown.example.test/metadata.json']];
        yield 'autre hôte de boucle locale' => [['redirect_uri' => 'http://127.0.0.1:49152/callback']];
        yield 'autre chemin' => [['redirect_uri' => 'http://localhost:49152/other']];
        yield 'https sur un autre hôte' => [['redirect_uri' => 'https://evil.example.test/callback']];
    }

    /**
     * Ni client connu ni redirection enregistrée : `invalid_client` de league,
     * en JSON, **sans** `Location` — même quand la demande porte aussi un
     * PKCE ou une ressource refusés.
     *
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('refusedClients')]
    public function testAnUnknownClientOrUnregisteredRedirectIsNeverRedirected(array $overrides): void
    {
        foreach ([[], ['code_challenge_method' => 'plain'], ['resource' => null]] as $alsoRefused) {
            $response = $this->authorize(http_build_query($this->validParameters([...$overrides, ...$alsoRefused])));

            $this->assertRefusedWithoutRedirect($response, Response::HTTP_UNAUTHORIZED, 'invalid_client');
        }
    }

    /**
     * Sans `redirect_uri`, league retomberait sur l'unique redirection
     * enregistrée — sans le port d'une boucle locale. Exigée : 400.
     */
    public function testAMissingRedirectUriIsRefusedWithoutRedirect(): void
    {
        $response = $this->authorize(http_build_query($this->validParameters(['redirect_uri' => null])));

        $this->assertRefusedWithoutRedirect($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expectedParameters(): iterable
    {
        foreach (self::EXPECTED_PARAMETERS as $parameter) {
            yield $parameter => [$parameter];
        }
    }

    /**
     * Un paramètre attendu reçu en tableau (`client_id[]=x`) : league le
     * passerait tel quel à une signature `string` (TypeError, 500). Refusé
     * avant lui, en 400, sans redirection.
     */
    #[DataProvider('expectedParameters')]
    public function testAnExpectedParameterGivenAsAnArrayIsA400NotA500(string $parameter): void
    {
        $parameters = $this->validParameters();
        $parameters[$parameter] = [$parameters[$parameter]];

        $response = $this->authorize(http_build_query($parameters));

        $this->assertRefusedWithoutRedirect($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
    }

    /**
     * `state` borné à 1024 caractères : au-delà, 400 sans redirection, et la
     * valeur n'est jamais renvoyée.
     */
    public function testAStateOverTheBoundIsRefusedWithoutEchoingIt(): void
    {
        $state = str_repeat('s', self::STATE_MAX_LENGTH + 1);

        $response = $this->authorize(http_build_query($this->validParameters(['state' => $state])));

        $this->assertRefusedWithoutRedirect($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
        self::assertStringNotContainsString(str_repeat('s', 64), (string) $response->getContent());
    }

    public function testAStateAtTheBoundIsAccepted(): void
    {
        $query = http_build_query($this->validParameters(['state' => str_repeat('s', self::STATE_MAX_LENGTH)]));

        $response = $this->authorize($query);

        self::assertSame($this->frontendBase().'/fr/oauth/consent?'.$query, $response->headers->get('Location'));
    }

    /**
     * Le firewall `oauth` ne lit aucun cookie : un `BEARER` (même illisible,
     * qui vaudrait un 401 sous le firewall `api`) ne change rien, ni à la
     * réponse valide, ni à un refus.
     */
    public function testABearerCookieGetsExactlyTheAnonymousAnswer(): void
    {
        $valid = http_build_query($this->validParameters());
        $refused = http_build_query($this->validParameters(['resource' => null]));
        $anonymousValid = $this->authorize($valid)->headers->get('Location');
        $anonymousRefused = $this->authorize($refused)->headers->get('Location');

        $this->client->getCookieJar()->set(new Cookie('BEARER', 'jeton-illisible-647'));

        $withCookieValid = $this->authorize($valid);
        self::assertSame(Response::HTTP_FOUND, $withCookieValid->getStatusCode());
        self::assertSame($anonymousValid, $withCookieValid->headers->get('Location'));
        $withCookieRefused = $this->authorize($refused);
        self::assertSame(Response::HTTP_FOUND, $withCookieRefused->getStatusCode());
        self::assertSame($anonymousRefused, $withCookieRefused->headers->get('Location'));
    }

    /**
     * `GET` seulement.
     */
    public function testAPostIs405(): void
    {
        $this->client->request('POST', self::PATH.'?'.http_build_query($this->validParameters()));

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Les paramètres d'une demande valide, chacun remplaçable ; `null` le retire.
     *
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    private function validParameters(array $overrides = []): array
    {
        $parameters = [
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'scope' => 'nominative:read',
            'state' => 'state-1',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => $this->resource(),
            ...$overrides,
        ];

        return array_filter($parameters, static fn(?string $value): bool => null !== $value);
    }

    /**
     * Sans `Accept-Language` par défaut : `Request::create()` en pose un
     * (`en-us,en;q=0.5`), que l'en-tête vide remplace — lu comme absent.
     *
     * @param array<string, string> $server
     */
    private function authorize(string $query, array $server = []): Response
    {
        $this->client->request('GET', self::PATH.'?'.$query, server: ['HTTP_ACCEPT_LANGUAGE' => '', ...$server]);

        return $this->client->getResponse();
    }

    /**
     * Un refus renvoyé au client : 302 vers la redirection demandée, avec
     * l'erreur, le `state` reçu et l'émetteur (RFC 9207), sans cache.
     */
    private function assertRefusedBackToTheClient(Response $response, string $error): void
    {
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith(self::LOOPBACK_REDIRECT.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame($error, $query['error'] ?? null);
        self::assertSame('state-1', $query['state'] ?? null);
        self::assertSame($this->publicApiBase().'/api/oauth', $query['iss'] ?? null);
        $this->assertNotStored($response);
    }

    /**
     * Un refus sans redirection : le JSON d'erreur de league (RFC 6749 §5.2),
     * pas un problem+json, et aucun `Location`.
     */
    private function assertRefusedWithoutRedirect(Response $response, int $status, string $error): void
    {
        self::assertSame($status, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        self::assertFalse($response->headers->has('Location'), 'Aucune redirection vers une URI non validée.');
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($error, $body['error'] ?? null);
        $this->assertNotStored($response);
    }

    private function assertNotStored(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers->get('Cache-Control'));
    }

    private function resource(): string
    {
        return $this->publicApiBase().'/api/mcp';
    }

    /**
     * La base publique de l'API telle que le conteneur de test la résout
     * (`phpunit.dist.xml` la force à vide : elle retombe sur celle du
     * frontend), barre finale retirée.
     */
    private function publicApiBase(): string
    {
        $base = self::getContainer()->getParameter('app.public_api_base_url');
        self::assertIsString($base);
        self::assertNotSame('', $base);

        return rtrim($base, '/');
    }

    private function frontendBase(): string
    {
        $base = self::getContainer()->getParameter('app.frontend_base_url');
        self::assertNotSame('', $base);

        return rtrim($base, '/');
    }

    private function purge(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $em->clear();
    }
}
