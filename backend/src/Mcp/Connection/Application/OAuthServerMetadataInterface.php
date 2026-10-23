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
     * @return array<string, string|bool|list<string>>
     */
    public function authorizationServer(): array;
}
