<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;

/**
 * Assemble les deux documents de métadonnées à partir des URL publiques,
 * écrites une fois dans {@see OAuthEndpoints} : aucune URL n'est reconstruite
 * ici.
 *
 * Ce qui est annoncé est ce que le serveur fait, et rien de plus — un client
 * tente tout ce qu'un document lui annonce :
 *  - un seul scope, `nominative:read` ;
 *  - le seul flux `authorization_code` (et son rafraîchissement), PKCE `S256`
 *    obligatoire, `plain` refusé ;
 *  - des clients publics seulement : aucune authentification au point de jeton
 *    ni à celui de révocation (`none`) ;
 *  - aucun enregistrement dynamique (pas de `registration_endpoint`), aucun
 *    document OpenID Connect : le client est pré-enregistré ou décrit par son
 *    document de métadonnées (`client_id_metadata_document_supported`) ;
 *  - l'émetteur renvoyé dans la réponse d'autorisation (`iss`, RFC 9207), ce
 *    qui protège le client d'une confusion entre serveurs d'autorisation ;
 *  - le jeton d'accès en en-tête `Authorization` seulement, jamais en
 *    paramètre ni en corps (RFC 6750 §2.1).
 */
final readonly class OAuthServerMetadata implements OAuthServerMetadataInterface
{
    public function __construct(
        private OAuthEndpoints $endpoints,
    ) {
    }

    public function protectedResource(): array
    {
        return [
            'resource' => $this->endpoints->resource(),
            'authorization_servers' => [$this->endpoints->issuer()],
            'scopes_supported' => [McpConnection::SCOPE_NOMINATIVE_READ],
            'bearer_methods_supported' => ['header'],
        ];
    }

    public function authorizationServer(): array
    {
        return [
            'issuer' => $this->endpoints->issuer(),
            'authorization_endpoint' => $this->endpoints->authorizationEndpoint(),
            'token_endpoint' => $this->endpoints->tokenEndpoint(),
            'revocation_endpoint' => $this->endpoints->revocationEndpoint(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [McpConnection::SCOPE_NOMINATIVE_READ],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }
}
