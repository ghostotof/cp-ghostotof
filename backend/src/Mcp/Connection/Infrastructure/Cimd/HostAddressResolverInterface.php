<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

/**
 * Résout un nom d'hôte en adresses IPv4 (CIMD, #651). Un port, pour que la
 * résolution soit celle que le serveur vérifie puis épingle, et pour qu'aucun
 * test n'interroge un DNS.
 */
interface HostAddressResolverInterface
{
    /**
     * @return list<string> les adresses IPv4 de l'hôte, une liste vide quand il n'en a aucune
     *                      ou que la résolution échoue
     */
    public function resolve(string $host): array;
}
