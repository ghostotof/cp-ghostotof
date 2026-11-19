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
     * La ligne `cimd` valide que le domaine construit depuis {@see self::json()}
     * (celle qu'une autorisation antérieure de claude.ai aurait laissée en base),
     * enregistrée le 2026-11-01 à 10:00.
     */
    public static function storedRow(): McpClient
    {
        $clientId = CimdClientId::tryFrom(self::CLIENT_ID) ?? throw new LogicException('Identifiant de test illisible.');

        return McpClient::discovered(ClientMetadataDocument::fromJson($clientId, self::json()), new MockClock('2026-11-01 10:00:00')->now());
    }

    /**
     * Une ligne `cimd` telle qu'une écriture antérieure ou à la main aurait pu
     * la laisser en base, dont le `client_id` est `$storedClientId`.
     *
     * La réflexion contourne volontairement la validation du domaine :
     * `McpClient::discovered()` n'accepte qu'un CimdClientId, donc le domaine
     * refuse de construire une ligne `cimd` dont l'identifiant est hors de la
     * forme CIMD (ou corrompu). C'est pourtant l'état que le garde du point
     * d'autorisation doit refuser (`invalid_client`, #651) : on réécrit donc la
     * propriété privée d'une ligne valide ({@see self::storedRow()}). Le
     * `clientId` rendu ne correspond plus au `client_id` du document dont la
     * ligne a été construite ({@see self::json()}) : le nom et les redirections
     * restent ceux de ce document, seul l'identifiant est remplacé.
     */
    public static function storedRowWhoseClientIdIs(string $storedClientId): McpClient
    {
        $row = self::storedRow();
        new ReflectionProperty(McpClient::class, 'clientId')->setValue($row, $storedClientId);

        return $row;
    }
}
