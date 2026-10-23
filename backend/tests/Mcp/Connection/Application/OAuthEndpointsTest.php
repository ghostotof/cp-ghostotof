<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\OAuthEndpoints;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les URL publiques du serveur d'autorisation et de la ressource protégée
 * (#647, spec 0007 phase 2) : toutes dérivées de deux bases, écrites une fois.
 *
 * En préprod et en prod, l'API est servie sur l'origine du frontend (même hôte
 * derrière l'Ingress) : `APP_PUBLIC_API_BASE_URL` y reste vide et retombe sur
 * `APP_FRONTEND_BASE_URL`. En dev seulement, l'API est sur une autre origine
 * que Vite, et la variable la nomme.
 */
final class OAuthEndpointsTest extends KernelTestCase
{
    /**
     * Le cas de la préprod et de la prod, lu dans le conteneur de test, où
     * `phpunit.dist.xml` force la variable à vide : la base publique de l'API
     * est celle du frontend. Le repli est celui du processeur `default:` de
     * Symfony, qui traite une valeur vide comme absente.
     */
    public function testAnEmptyPublicApiBaseFallsBackToTheFrontendBase(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $frontend = $container->getParameter('app.frontend_base_url');
        $publicApi = $container->getParameter('app.public_api_base_url');
        self::assertIsString($publicApi);

        self::assertSame($frontend, $publicApi);

        $endpoints = new OAuthEndpoints($publicApi, $frontend);

        self::assertSame($frontend.'/api/oauth', $endpoints->issuer());
        self::assertSame($frontend.'/api/mcp', $endpoints->resource());
    }

    public function testEveryEndpointDerivesFromTheFrontendBaseAlone(): void
    {
        $endpoints = new OAuthEndpoints('https://site.example', 'https://site.example');

        self::assertSame('https://site.example/api/oauth', $endpoints->issuer());
        self::assertSame('https://site.example/api/mcp', $endpoints->resource());
        self::assertSame('https://site.example/api/oauth/authorize', $endpoints->authorizationEndpoint());
        self::assertSame('https://site.example/api/oauth/token', $endpoints->tokenEndpoint());
        self::assertSame('https://site.example/api/oauth/revoke', $endpoints->revocationEndpoint());
        self::assertSame('https://site.example/.well-known/oauth-protected-resource/api/mcp', $endpoints->protectedResourceMetadataUrl());
        self::assertSame('https://site.example/fr/oauth/consent', $endpoints->consentPage('fr'));
    }

    /**
     * Le cas du dev : l'API (nginx) et le frontend (Vite) sur deux origines.
     * Tout ce qui est OAuth ou MCP suit l'API ; seule la page de consentement,
     * une page du frontend, suit le frontend.
     */
    public function testADistinctApiBaseCarriesEverythingButTheConsentPage(): void
    {
        $endpoints = new OAuthEndpoints('http://localhost:8080', 'http://localhost:5173');

        self::assertSame('http://localhost:8080/api/oauth', $endpoints->issuer());
        self::assertSame('http://localhost:8080/api/mcp', $endpoints->resource());
        self::assertSame('http://localhost:8080/api/oauth/authorize', $endpoints->authorizationEndpoint());
        self::assertSame('http://localhost:8080/api/oauth/token', $endpoints->tokenEndpoint());
        self::assertSame('http://localhost:8080/api/oauth/revoke', $endpoints->revocationEndpoint());
        self::assertSame('http://localhost:8080/.well-known/oauth-protected-resource/api/mcp', $endpoints->protectedResourceMetadataUrl());
        self::assertSame('http://localhost:5173/en/oauth/consent', $endpoints->consentPage('en'));
    }

    /**
     * Une base écrite avec sa barre finale ne double pas la barre des chemins :
     * l'émetteur et la ressource sont comparés à l'octet près par les clients
     * (RFC 8414 §3.3, RFC 9728 §3.3).
     */
    public function testATrailingSlashOfEitherBaseIsStripped(): void
    {
        $endpoints = new OAuthEndpoints('https://api.example/', 'https://site.example//');

        self::assertSame('https://api.example/api/oauth', $endpoints->issuer());
        self::assertSame('https://site.example/fr/oauth/consent', $endpoints->consentPage('fr'));
    }
}
