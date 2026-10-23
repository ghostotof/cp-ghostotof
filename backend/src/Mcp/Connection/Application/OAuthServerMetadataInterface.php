<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

/**
 * Les deux documents de métadonnées que publie la connexion MCP (#647, spec
 * 0007 phase 2), tels qu'un client les lit : la ressource protégée (RFC 9728)
 * qui désigne le serveur d'autorisation, puis le serveur d'autorisation
 * (RFC 8414) qui décrit ses points et ce qu'il accepte.
 *
 * Interface parce que les contrôleurs qui les servent dépendent d'elle et non
 * de leur assemblage (règle du dépôt : contrôleur → interface → service).
 */
interface OAuthServerMetadataInterface
{
    /**
     * Le document de la ressource protégée, servi à
     * `/.well-known/oauth-protected-resource/api/mcp`.
     *
     * @return array{resource: string, authorization_servers: list<string>, scopes_supported: list<string>, bearer_methods_supported: list<string>}
     */
    public function protectedResource(): array;

    /**
     * Le document du serveur d'autorisation, servi à
     * `/.well-known/oauth-authorization-server/api/oauth`.
     *
     * @return array{
     *     issuer: string,
     *     authorization_endpoint: string,
     *     token_endpoint: string,
     *     revocation_endpoint: string,
     *     response_types_supported: list<string>,
     *     response_modes_supported: list<string>,
     *     grant_types_supported: list<string>,
     *     code_challenge_methods_supported: list<string>,
     *     token_endpoint_auth_methods_supported: list<string>,
     *     revocation_endpoint_auth_methods_supported: list<string>,
     *     scopes_supported: list<string>,
     *     client_id_metadata_document_supported: bool,
     *     authorization_response_iss_parameter_supported: bool,
     * }
     */
    public function authorizationServer(): array;
}
