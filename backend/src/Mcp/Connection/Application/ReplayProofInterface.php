<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Psr\Http\Message\ServerRequestInterface;

/**
 * La preuve qu'un code d'autorisation rejoué vient de qui détient son
 * vérificateur PKCE (#726). Mise en œuvre dans Infrastructure\League, qui
 * seul sait lire le défi que la charge chiffrée du code porte.
 *
 * Le point de jeton ne révoque la connexion d'un code rejoué que sur cette
 * preuve : un code se lit sur la redirection, que l'on peut intercepter ; le
 * vérificateur, le client légitime ne l'a jamais transmis qu'au point de
 * jeton (RFC 7636 §1). Sans preuve, le rejeu est refusé et audité, la
 * connexion laissée vivante.
 */
interface ReplayProofInterface
{
    /**
     * Vrai seulement quand le corps analysé de la requête porte, en chaînes,
     * un `code` dont le serveur déchiffre le défi `S256` et un
     * `code_verifier` qui le vérifie (RFC 7636 §4.6) ; faux pour tout le
     * reste — corps absent, code illisible ou sans défi `S256`, vérificateur
     * absent ou faux.
     */
    public function provesPossession(ServerRequestInterface $request): bool;
}
