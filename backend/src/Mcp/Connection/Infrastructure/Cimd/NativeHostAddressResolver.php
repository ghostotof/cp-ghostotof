<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

/**
 * La résolution du système, par `dns_get_record()`.
 *
 * Le nom est enraciné (`claude.ai.`) : sans le point final, la liste de
 * recherche du résolveur (`search`, `ndots`) s'applique et un nom court peut
 * aboutir sur un hôte que le `client_id` ne désigne pas. Seuls les
 * enregistrements A sont demandés : le client HTTP épingle une IPv4, et une
 * adresse que le serveur ne vérifie pas n'est jamais contactée.
 *
 * Aucun test n'instancie cette classe pour résoudre : les tests ne touchent
 * pas au DNS, ils passent par une doublure du port. Sa résolution réelle se
 * vérifie à l'essai de préprod de la tâche 14 du plan.
 */
final readonly class NativeHostAddressResolver implements HostAddressResolverInterface
{
    public function resolve(string $host): array
    {
        // Une résolution manquée émet un avertissement PHP : il est neutralisé
        // le temps de l'appel seulement, le gestionnaire étant rétabli quoi
        // qu'il arrive. Le refus se lit sur la liste vide, pas sur le bruit.
        set_error_handler(static fn (): bool => true);
        try {
            $records = dns_get_record($host.'.', DNS_A);
        } finally {
            restore_error_handler();
        }

        if (!\is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && \is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }
        }

        return $addresses;
    }
}
