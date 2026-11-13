<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Une entrée de la liste des domaines dont un client CIMD est admis (#651,
 * paramètre `app.mcp.cimd.allowed_domains` de `config/services.yaml`) n'est
 * pas un nom d'hôte tel qu'un `client_id` CIMD peut en porter : une majuscule,
 * un schéma, un port, un chemin, une adresse littérale, un nom sans point.
 *
 * Défaut de configuration, jamais une erreur client : levée à la construction
 * du service {@see \App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains},
 * aucune entrée `framework.exceptions`, un 500 `critical` au premier usage.
 * Refusée plutôt qu'ignorée : une entrée qu'aucun hôte ne peut égaler
 * n'autoriserait jamais rien, en silence.
 *
 * Le message ne cite jamais l'entrée reçue.
 */
final class InvalidAllowedClientDomainException extends InvalidArgumentException
{
    public static function malformed(): self
    {
        return new self('Un domaine autorisé des clients CIMD doit être un nom d\'hôte DNS en minuscules, avec au moins un point, sans schéma, port ni chemin.');
    }
}
