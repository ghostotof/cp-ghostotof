<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\OAuthServerMetadataInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le document de métadonnées du serveur d'autorisation (RFC 8414), au chemin
 * qu'un client calcule depuis l'émetteur `<base>/api/oauth` : le chemin bien
 * connu inséré avant le chemin de l'émetteur (§3.1). Hors `/api` par la
 * norme ; son propre firewall (`oauth_server_metadata`, sans
 * authentificateur) et sa propre règle `access_control` (`PUBLIC_ACCESS`),
 * inscrits dans `RouterScopeTest::NON_API_PATHS`.
 *
 * Aucun document OpenID Connect n'est servi, ni à
 * `/.well-known/openid-configuration` ni sous l'émetteur : le serveur n'émet
 * pas de jeton d'identité.
 */
final readonly class AuthorizationServerMetadataController
{
    public function __construct(
        private OAuthServerMetadataInterface $metadata,
    ) {
    }

    #[Route('/.well-known/oauth-authorization-server/api/oauth', name: 'oauth_authorization_server_metadata', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return MetadataDocumentResponse::of($this->metadata->authorizationServer());
    }
}
