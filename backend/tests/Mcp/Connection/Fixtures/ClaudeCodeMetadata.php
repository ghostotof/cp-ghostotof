<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Fixtures;

/**
 * Le document de métadonnées que `https://claude.ai/oauth/claude-code-client-metadata`
 * servait le 2026-11-13 (CIMD, #651), recopié tel quel : le cas réel contre lequel
 * la validation est écrite, jamais une récupération (aucun accès réseau dans les tests).
 */
final class ClaudeCodeMetadata
{
    public const string CLIENT_ID = 'https://claude.ai/oauth/claude-code-client-metadata';

    /** Le document, tel qu'un hôte le sert : une chaîne JSON. */
    public static function json(): string
    {
        return '{"client_id":"https://claude.ai/oauth/claude-code-client-metadata","client_name":"Claude Code","client_uri":"https://claude.ai","redirect_uris":["http://localhost/callback","http://127.0.0.1/callback"],"grant_types":["authorization_code","refresh_token"],"response_types":["code"],"token_endpoint_auth_method":"none"}';
    }
}
