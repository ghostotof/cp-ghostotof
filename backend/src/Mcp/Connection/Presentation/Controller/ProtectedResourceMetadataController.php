<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\OAuthServerMetadataInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le document de métadonnées de la ressource protégée (RFC 9728), au chemin
 * qu'un client MCP calcule depuis l'URL du serveur : le chemin bien connu
 * suivi du chemin de la ressource (§3.1). Hors `/api` par la norme ; son
 * propre firewall (`oauth_resource_metadata`, sans authentificateur) et sa
 * propre règle `access_control` (`PUBLIC_ACCESS`), inscrits dans
 * `RouterScopeTest::NON_API_PATHS`.
 */
final readonly class ProtectedResourceMetadataController
{
    public function __construct(
        private OAuthServerMetadataInterface $metadata,
    ) {
    }

    #[Route('/.well-known/oauth-protected-resource/api/mcp', name: 'oauth_protected_resource_metadata', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return MetadataDocumentResponseFactory::create($this->metadata->protectedResource());
    }
}
