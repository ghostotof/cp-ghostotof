<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;

/**
 * Pré-enregistre un client MCP (#647) : il n'existe aucun enregistrement
 * dynamique (aucun `registration_endpoint`), un client n'est connu que par
 * cette voie.
 */
interface McpClientRegistrarInterface
{
    /**
     * Les redirections arrivent déjà validées : le type l'impose, aucune
     * chaîne brute n'atteint McpClient::register() par ici.
     *
     * @param non-empty-list<RedirectUri> $redirectUris
     *
     * @throws McpClientAlreadyRegisteredException si l'identifiant public est déjà pris
     */
    public function register(string $clientId, string $name, array $redirectUris): McpClient;
}
