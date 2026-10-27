<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * L'audit d'un refus du point de jeton (#725). Mis en œuvre dans
 * Infrastructure\League, qui seul sait lire un code ou un jeton de
 * rafraîchissement (la charge chiffrée par league) et classer un refus de
 * league.
 */
interface GrantRefusalAuditInterface
{
    /**
     * Écrit la ligne `mcp-grant-refused` quand le refus porte sur un code ou
     * un jeton de rafraîchissement **authentique** — que le serveur déchiffre —
     * dont la connexion existe encore en base, et que le client présentant
     * est connu. Rien sinon : un artefact illisible, un client inconnu ou une
     * connexion disparue ne désignent personne, et un flot de requêtes
     * quelconques n'écrit aucune ligne.
     *
     * Appelée après la transaction du point de jeton, annulée : la lecture de
     * la connexion vaut EntityManager fermé.
     */
    public function record(ServerRequestInterface $request, OAuthServerException $refusal): void;
}
