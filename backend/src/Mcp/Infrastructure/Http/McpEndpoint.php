<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

/**
 * Le chemin du serveur MCP, écrit une fois (#673) : le bundle le lit
 * (`mcp.yaml`, `!php/const`), et le code qui juge une requête sur ce chemin
 * le nomme par cette constante ({@see InboundMessageRefusalListener},
 * {@see \App\Mcp\Infrastructure\Log\McpCallLog},
 * {@see \App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber}).
 *
 * Sous `/api` : l'Ingress n'envoie au backend que ce préfixe (ADR 0006). Ce
 * que PHP ne lit pas — le pattern du firewall `mcp`, les locations nginx, le
 * front — est comparé à cette valeur par `McpEndpointPathTest`.
 */
final class McpEndpoint
{
    public const string PATH = '/api/mcp';

    private function __construct()
    {
    }
}
