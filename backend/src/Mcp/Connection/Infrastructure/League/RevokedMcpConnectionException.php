<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use RuntimeException;

/**
 * league demande d'enregistrer un code ou un jeton pour une connexion MCP
 * déjà révoquée (#647) : rien ne naît d'une connexion morte. league a
 * déjà demandé « révoqué ? » pour le code ou le rafraîchissement présenté,
 * et ce dépôt y répond vrai quand la connexion l'est : seule une révocation
 * survenue entre cette question et l'enregistrement (une course) ou un défaut
 * du serveur y mène. Refusé plutôt qu'émis : aucun jeton valide sur une
 * connexion révoquée, pas même pour une heure. Aucune entrée
 * `framework.exceptions`, un 500 `critical`. Le message ne cite aucun
 * identifiant.
 */
final class RevokedMcpConnectionException extends RuntimeException
{
    public static function atIssuance(): self
    {
        return new self('Un code ou un jeton a été demandé pour une connexion MCP déjà révoquée.');
    }
}
