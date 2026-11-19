<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;

/**
 * Port : un client que la bibliothèque OAuth a jugé (`getClient()` d'une
 * demande d'autorisation) et qui sait rendre le McpClient du domaine qu'il
 * enveloppe.
 *
 * Il permet à la couche Application (AuthorizationRequestSummary) de lire
 * l'origine d'identité du client jugé sans importer l'adaptateur de league
 * (inversion de dépendance : Infrastructure implémente, Application dépend).
 */
interface JudgedClientInterface
{
    public function mcpClient(): McpClient;
}
