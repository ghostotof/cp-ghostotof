<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Shared\Domain\Security\AccountReference;

/**
 * « Mes connexions MCP » (#650) : ce que la personne du palier de confiance
 * fait de ses propres connexions. Le contrôleur n'en connaît que cette
 * interface.
 */
interface OwnMcpConnectionsInterface
{
    /**
     * Toutes les connexions du compte — actives, expirées, révoquées que la
     * purge n'a pas encore supprimées —, la plus récemment octroyée d'abord,
     * avec leur état à l'instant de l'horloge. Une requête.
     *
     * @return list<OwnMcpConnectionView>
     */
    public function of(AccountReference $account): array;
}
