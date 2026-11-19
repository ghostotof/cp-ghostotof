<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use App\Mcp\Connection\Infrastructure\Cimd\HostAddressResolverInterface;

/**
 * Doublure du résolveur d'adresses (#651) : une table hôte → adresses écrite
 * par le test, aucune requête DNS. Un hôte absent de la table ne résout pas
 * (liste vide), comme un nom inconnu du résolveur natif.
 *
 * Elle garde chaque hôte reçu, dans l'ordre : un test prouve ainsi que la
 * récupération a bien demandé l'hôte du `client_id`, ou qu'elle n'a rien
 * demandé du tout (un client pré-enregistré, un domaine refusé).
 */
final class FixedHostAddressResolver implements HostAddressResolverInterface
{
    /**
     * @var list<string>
     */
    private array $resolvedHosts = [];

    /**
     * @param array<string, list<string>> $addresses les adresses de chaque hôte
     */
    public function __construct(private readonly array $addresses = [])
    {
    }

    public function resolve(string $host): array
    {
        $this->resolvedHosts[] = $host;

        return $this->addresses[$host] ?? [];
    }

    /**
     * @return list<string> les hôtes reçus, dans l'ordre des appels
     */
    public function resolvedHosts(): array
    {
        return $this->resolvedHosts;
    }
}
