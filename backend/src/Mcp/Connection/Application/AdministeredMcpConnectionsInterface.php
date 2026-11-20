<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

/**
 * Les connexions MCP de **tous** les comptes, pour l'administration (#652).
 */
interface AdministeredMcpConnectionsInterface
{
    /**
     * Les connexions avec leur état à l'instant de l'horloge, la plus
     * récemment octroyée d'abord : **toutes les vivantes** (actives, et échues
     * que la purge n'a pas encore marquées) et **les
     * {@see AdministeredMcpConnections::TERMINATED_LIMIT} dernières terminées**.
     *
     * Les terminées viennent de deux lectures que la limite ne peut pas
     * borner ensemble en SQL, d'où la fusion en PHP : les révoquées (et les
     * expirées marquées), classées à leur `revoked_at`, et les **accords
     * abandonnés**. Un accord abandonné est un octroi dont le code n'a jamais
     * été échangé : la supplantation d'une connexion par la suivante n'a lieu
     * qu'à l'échange du code, et l'expiration d'une heure n'est marquée en base
     * que par la purge. Jusqu'à son passage, ces lignes ne sont pas révoquées
     * mais leur échéance est dépassée : elles sont lues à part, bornées, et
     * rangées parmi les terminées, à leur fin réelle (octroi + délai du code
     * non échangé).
     *
     * Trois requêtes, quel que soit le nombre de connexions, dans un même
     * instantané.
     *
     * @return list<AdministeredMcpConnectionView>
     */
    public function list(): array;
}
