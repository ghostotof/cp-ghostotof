<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Les connexions MCP. L'implémentation vit dans
 * Infrastructure\Doctrine\McpConnectionRepository.
 */
interface McpConnectionRepositoryInterface
{
    /** La connexion, avec son compte et son client, en une requête. */
    public function findOneById(Uuid $id): ?McpConnection;

    public function add(McpConnection $connection): void;
}
