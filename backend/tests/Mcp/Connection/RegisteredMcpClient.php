<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use DateTimeImmutable;

/**
 * Un client MCP enregistré pour un test, à partir de chaînes : chacune passe
 * par son Value Object, comme McpClient::register() l'exige — une valeur que
 * le domaine refuse fait échouer le test qui l'écrit, au lieu d'un client que
 * la production ne pourrait pas enregistrer.
 */
final class RegisteredMcpClient
{
    /**
     * @param list<string> $redirectUris
     */
    public static function of(string $clientId, string $name, array $redirectUris, ?DateTimeImmutable $at = null): McpClient
    {
        return McpClient::register(
            McpClientId::fromString($clientId),
            McpClientName::fromString($name),
            array_map(RedirectUri::fromString(...), $redirectUris),
            $at ?? new DateTimeImmutable(),
        );
    }
}
