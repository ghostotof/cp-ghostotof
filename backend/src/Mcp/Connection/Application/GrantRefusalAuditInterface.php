<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\GrantRefusal;
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
     *
     * `$cause`, quand l'appelant la connaît — un rejeu de code sans preuve
     * PKCE, `GrantRefusal::ReplayUnproven` (#726), dont le refus est mot pour
     * mot celui d'un code révoqué —, nomme le refus ; sans elle, il est classé
     * d'après l'indication de league. Elle ne change rien d'autre : une panne
     * du serveur ou un client inconnu ne sont pas audités davantage.
     */
    public function record(ServerRequestInterface $request, OAuthServerException $refusal, ?GrantRefusal $cause = null): void;
}
