<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le point de révocation OAuth de la connexion MCP (#647, RFC 7009),
 * `POST /api/oauth/revoke` : un client rend un jeton dont il n'a plus l'usage.
 * Mis en œuvre dans Infrastructure\League, qui seul sait lire un jeton de
 * rafraîchissement (la charge chiffrée par league) et retrouver un jeton par
 * son empreinte.
 *
 * Révoque le **jeton**, jamais la connexion : la révocation d'une connexion
 * et sa ligne d'audit sont l'objet des tâches 10 et 11 de la spec (#649,
 * #650). Une connexion dont tous les jetons sont révoqués vit jusqu'à ce que
 * son client en redemande, par un nouveau consentement.
 *
 * Interface parce que le contrôleur qui le sert dépend d'elle et non de sa
 * mise en œuvre (règle du dépôt : contrôleur → interface → service).
 */
interface RevocationEndpointInterface
{
    /**
     * Révoque le jeton présenté s'il a été émis au client qui le présente.
     * Un jeton inconnu, illisible ou émis à un autre client ne change rien et
     * ne lève rien : la réponse est celle d'un succès (RFC 7009 §2.2), rien
     * ne dit à l'appelant qu'un jeton existe.
     *
     * @throws OAuthServerException `invalid_request` (400) sans `token` ou sur
     *                              un paramètre répété, `invalid_client` (401)
     *                              sans client enregistré
     */
    public function revoke(ServerRequestInterface $request): void;
}
