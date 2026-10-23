<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpClient;

/**
 * Les clients MCP enregistrés. L'implémentation vit dans
 * Infrastructure\Doctrine\McpClientRepository.
 */
interface McpClientRepositoryInterface
{
    /** Par l'identifiant public du client (`client_id` d'OAuth), jamais par sa clé UUID. */
    public function findOneByClientId(string $clientId): ?McpClient;

    /**
     * Enregistre un nouveau client. Un identifiant public déjà pris est refusé
     * par la base, sous la contrainte McpClient::CLIENT_ID_UNIQUE_INDEX.
     */
    public function add(McpClient $client): void;
}
