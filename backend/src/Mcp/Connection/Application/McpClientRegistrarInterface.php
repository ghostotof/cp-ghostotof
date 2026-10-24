<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;

/**
 * Pré-enregistre un client MCP (#647) : il n'existe aucun enregistrement
 * dynamique (aucun `registration_endpoint`), un client n'est connu que par
 * cette voie.
 */
interface McpClientRegistrarInterface
{
    /**
     * L'identifiant, le nom et les redirections arrivent déjà validés par
     * leurs Value Objects : le type l'impose, aucune chaîne brute n'atteint
     * McpClient::register() par ici.
     *
     * @param non-empty-list<RedirectUri> $redirectUris
     *
     * @throws McpClientAlreadyRegisteredException si l'identifiant public est déjà pris
     */
    public function register(McpClientId $clientId, McpClientName $name, array $redirectUris): McpClient;
}
