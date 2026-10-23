<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Presentation\Controller\AuthorizationServerMetadataController;
use App\Mcp\Connection\Presentation\Controller\ProtectedResourceMetadataController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les deux documents de métadonnées OAuth du serveur MCP (#647, spec 0007
 * phase 2), servis **hors `/api`**, aux URL canoniques que les clients MCP
 * calculent eux-mêmes : RFC 9728 §3.1 pour la ressource protégée (chemin bien
 * connu suivi du chemin de la ressource), RFC 8414 §3.1 pour le serveur
 * d'autorisation (chemin bien connu suivi du chemin de l'émetteur).
 *
 * Chaque document est comparé **en entier** (égalité stricte de tableau) : une
 * clé ajoutée — un `registration_endpoint`, un document OIDC — annonce aux
 * clients une capacité que le serveur n'a pas, ils la tenteraient. Les URL
 * attendues sont recalculées ici depuis le paramètre de configuration, jamais
 * lues dans `OAuthEndpoints` : le test resterait vert sur une URL fausse
 * qu'il recopierait.
 */
final class OAuthMetadataTest extends WebTestCase
{
    private const string PROTECTED_RESOURCE_PATH = '/.well-known/oauth-protected-resource/api/mcp';

    private const string AUTHORIZATION_SERVER_PATH = '/.well-known/oauth-authorization-server/api/oauth';

    public function testTheProtectedResourceDocumentIsExactlyTheExpectedOne(): void
    {
        $client = self::createClient();
        $base = $this->publicApiBase();

        $document = $this->getDocument($client, self::PROTECTED_RESOURCE_PATH);

        self::assertSame(
            [
                'resource' => $base.'/api/mcp',
                'authorization_servers' => [$base.'/api/oauth'],
                'scopes_supported' => ['nominative:read'],
                'bearer_methods_supported' => ['header'],
            ],
            $document,
        );
    }

    public function testTheAuthorizationServerDocumentIsExactlyTheExpectedOne(): void
    {
        $client = self::createClient();
        $base = $this->publicApiBase();

        $document = $this->getDocument($client, self::AUTHORIZATION_SERVER_PATH);

        self::assertSame(
            [
                'issuer' => $base.'/api/oauth',
                'authorization_endpoint' => $base.'/api/oauth/authorize',
                'token_endpoint' => $base.'/api/oauth/token',
                'revocation_endpoint' => $base.'/api/oauth/revoke',
                'response_types_supported' => ['code'],
                'response_modes_supported' => ['query'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
                'revocation_endpoint_auth_methods_supported' => ['none'],
                'scopes_supported' => ['nominative:read'],
                'client_id_metadata_document_supported' => true,
                'authorization_response_iss_parameter_supported' => true,
            ],
            $document,
        );
        // Redit à part, l'égalité ci-dessus l'impliquant déjà : aucun
        // enregistrement dynamique (RFC 7591), les clients sont
        // pré-enregistrés ou décrits par leur document de métadonnées.
        self::assertArrayNotHasKey('registration_endpoint', $document);
    }

    /**
     * L'émetteur annoncé est le préfixe de chaque point, et la ressource celle
     * que le document de la ressource protégée nomme : un client compare les
     * deux à l'octet près (RFC 8414 §3.3, RFC 9728 §3.3).
     */
    public function testBothDocumentsNameTheSameIssuer(): void
    {
        $client = self::createClient();

        $resource = $this->getDocument($client, self::PROTECTED_RESOURCE_PATH);
        $server = $this->getDocument($client, self::AUTHORIZATION_SERVER_PATH);

        self::assertSame([$server['issuer']], $resource['authorization_servers']);
    }

    /**
     * RFC 9728 §3.1 : l'URL du document telle qu'`OAuthEndpoints` la publie —
     * et la publiera dans le `resource_metadata` du `WWW-Authenticate` — est
     * celle que le routeur sert, par ce contrôleur. Une dérive entre la route,
     * `OAuthEndpoints` et ce pointeur rougit ici, au lieu d'envoyer les
     * clients sur un 404.
     */
    public function testTheAdvertisedProtectedResourceMetadataUrlIsTheRoutedPath(): void
    {
        self::bootKernel();
        $url = self::getContainer()->get(OAuthEndpoints::class)->protectedResourceMetadataUrl();

        $this->assertRoutedTo($this->pathOf($url), ProtectedResourceMetadataController::class);
    }

    /**
     * RFC 8414 §3.1 : le chemin bien connu inséré avant le chemin de
     * l'émetteur, celui qu'un client calcule depuis `issuer()`, est celui que
     * le routeur sert, par ce contrôleur.
     */
    public function testTheAuthorizationServerMetadataPathDerivedFromTheIssuerIsTheRoutedPath(): void
    {
        self::bootKernel();
        $issuer = self::getContainer()->get(OAuthEndpoints::class)->issuer();

        $this->assertRoutedTo('/.well-known/oauth-authorization-server'.$this->pathOf($issuer), AuthorizationServerMetadataController::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDocumentPaths(): iterable
    {
        yield 'ressource protégée' => [self::PROTECTED_RESOURCE_PATH];
        yield 'serveur d\'autorisation' => [self::AUTHORIZATION_SERVER_PATH];
    }

    /**
     * Des documents publics et stables (ils ne changent qu'avec un
     * déploiement) : cachables une heure, et sans cookie posé, ce qu'un cache
     * partagé servirait à tout le monde.
     */
    #[DataProvider('provideDocumentPaths')]
    public function testEachDocumentIsPublicJsonCachedOneHour(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path);
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->hasCacheControlDirective('private'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame([], $response->headers->getCookies());
    }

    /**
     * `GET` seulement : le routeur refuse toute autre méthode en 405, avant
     * le firewall.
     */
    #[DataProvider('provideDocumentPaths')]
    public function testAPostOnADocumentIs405(string $path): void
    {
        $client = self::createClient();
        $client->request('POST', $path);

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $client->getResponse()->getStatusCode());
    }

    /**
     * Le firewall de chaque document n'a aucun authentificateur : un cookie
     * `BEARER` (même forgé) n'est ni lu ni refusé, et ne change rien au corps.
     * Sous le firewall `api`, un JWT illisible vaudrait un 401.
     */
    #[DataProvider('provideDocumentPaths')]
    public function testABearerCookieChangesNothing(string $path): void
    {
        $client = self::createClient();
        $anonymous = $this->getDocument($client, $path);

        $client->getCookieJar()->set(new Cookie('BEARER', 'jeton-illisible-647'));
        $withCookie = $this->getDocument($client, $path);

        self::assertSame($anonymous, $withCookie);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOpenIdConfigurationPaths(): iterable
    {
        yield 'racine' => ['/.well-known/openid-configuration'];
        yield 'suffixée par l\'émetteur (RFC 8414 §5)' => ['/.well-known/openid-configuration/api/oauth'];
        yield 'sous l\'émetteur (OIDC Discovery §4)' => ['/api/oauth/.well-known/openid-configuration'];
    }

    /**
     * Aucun document OIDC : le serveur n'émet pas de jeton d'identité, et un
     * client qui en trouverait un tenterait OpenID Connect. Les trois
     * emplacements qu'un client sonde répondent 404.
     */
    #[DataProvider('provideOpenIdConfigurationPaths')]
    public function testNoOpenIdConfigurationIsServed(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
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

    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path, \sprintf('%s n\'a pas de chemin.', $url));

        return $path;
    }

    /**
     * @param class-string $controller
     */
    private function assertRoutedTo(string $path, string $controller): void
    {
        $route = self::getContainer()->get('router')->match($path);

        self::assertSame($controller, $route['_controller'] ?? null, \sprintf('%s n\'est pas servi par %s.', $path, $controller));
    }

    /**
     * @return array<string, mixed>
     */
    private function getDocument(KernelBrowser $client, string $path): array
    {
        $client->request('GET', $path);
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), \sprintf('%s devrait répondre 200.', $path));

        $document = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }
}
