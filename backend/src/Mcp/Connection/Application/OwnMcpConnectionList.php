<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

/**
 * Ce que « Mes connexions MCP » rend à la personne (#650, R3) : ses connexions
 * — toutes les non révoquées, les dernières terminées — et si l'historique des
 * terminées a été coupé (`terminatedTruncated` : il en existe de plus
 * anciennes, que la liste ne montre pas).
 */
final readonly class OwnMcpConnectionList
{
    /**
     * @param list<OwnMcpConnectionView> $connections la plus récemment octroyée d'abord
     */
    public function __construct(
        public array $connections,
        public bool $terminatedTruncated,
    ) {
    }
}
