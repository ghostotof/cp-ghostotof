<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;

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
     * quelconques n'écrit aucune ligne. La cause est classée d'après
     * l'indication de league.
     *
     * Appelée après la transaction du point de jeton, annulée : la lecture de
     * la connexion vaut EntityManager fermé.
     */
    public function record(ServerRequestInterface $request, OAuthServerException $refusal): void;

    /**
     * Écrit la ligne `mcp-grant-refused`, cause `replay-unproven` (#726), pour
     * un code déjà échangé rejoué sur une connexion active **sans** la preuve
     * de son vérificateur PKCE : refusé comme un code révoqué, mot pour mot,
     * que l'indication de league seule classerait `code-refused`.
     *
     * La connexion est celle que la compromission a déjà désignée, sous le
     * verrou de league (`CompromisedMcpConnectionException::connectionId()`) :
     * le code n'est pas redéchiffré pour la retrouver (#726, CR4). Une
     * variante dédiée plutôt qu'une cause et une connexion facultatives de
     * record() : ni une cause sans connexion connue ni une connexion connue
     * classée par league ne s'écrivent, et le refus n'a rien à trier — jamais
     * une panne, jamais un client inconnu (league l'a authentifié avant de
     * juger le code). Rien n'est écrit si la connexion n'est plus en base ou
     * si le corps ne nomme pas son client en chaîne.
     *
     * Appelée après la transaction annulée, comme record().
     */
    public function recordUnprovenReplay(ServerRequestInterface $request, Uuid $connectionId): void;
}
