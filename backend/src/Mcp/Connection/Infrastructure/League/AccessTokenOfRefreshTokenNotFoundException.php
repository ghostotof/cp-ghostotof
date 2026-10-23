<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use LogicException;

/**
 * league demande d'enregistrer un jeton de rafraîchissement dont le jeton
 * d'accès n'est pas en base (#647). league émet toujours le jeton d'accès,
 * puis le rafraîchissement qui l'accompagne, dans la même requête : seul un
 * défaut du serveur y mène. Refusé plutôt qu'enregistré sans son lien, que la
 * rotation (#649) lit pour révoquer le jeton d'accès. Aucune entrée
 * `framework.exceptions`, un 500 `critical`. Le message ne cite aucun
 * identifiant.
 */
final class AccessTokenOfRefreshTokenNotFoundException extends LogicException
{
    public static function notPersisted(): self
    {
        return new self('Le jeton d\'accès d\'un jeton de rafraîchissement de connexion MCP est introuvable en base.');
    }
}
