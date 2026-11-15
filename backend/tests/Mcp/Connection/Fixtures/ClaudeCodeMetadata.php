<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Fixtures;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use LogicException;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;

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

    /**
     * Une ligne `cimd` telle qu'une écriture antérieure ou à la main aurait pu
     * la laisser en base, dont le `client_id` est `$storedClientId`.
     *
     * La réflexion est voulue : `McpClient::discovered()` n'accepte qu'un
     * CimdClientId, donc le domaine refuse de construire une ligne `cimd` dont
     * l'identifiant est hors de la forme CIMD (ou corrompu). C'est pourtant
     * l'état que le garde du point d'autorisation doit refuser (`invalid_client`,
     * #651) : le seul moyen de le poser sans contourner la validation est de
     * réécrire la propriété d'une ligne valide. La ligne reste, pour le reste,
     * celle que construit le domaine (documents de {@see self::json()}).
     */
    public static function storedRowWhoseClientIdIs(string $storedClientId): McpClient
    {
        $clientId = CimdClientId::tryFrom(self::CLIENT_ID) ?? throw new LogicException('Identifiant de test illisible.');
        $row = McpClient::discovered(ClientMetadataDocument::fromJson($clientId, self::json()), new MockClock('2026-11-01 10:00:00')->now());
        new ReflectionProperty(McpClient::class, 'clientId')->setValue($row, $storedClientId);

        return $row;
    }
}
