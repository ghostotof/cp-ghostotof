<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use App\Mcp\Connection\Application\BearerChallenge;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le 401 de `/api/mcp`, construit en un seul endroit (#648, relecture) pour
 * ses deux auteurs, dont seul le défi diffère :
 *
 *  - {@see self::missingToken()} : l'autorisation différée de l'outil
 *    nominatif, sans jeton ({@see DeferredAuthorizationListener}) ;
 *  - {@see self::refusedToken()} : un jeton présenté et refusé, sur toute
 *    requête ({@see \App\Mcp\Connection\Infrastructure\Security\McpBearerAuthenticator}).
 *
 * Le même gabarit : corps vide, `WWW-Authenticate` portant le défi de
 * {@see BearerChallenge}, `Cache-Control: no-store`, et la réponse inscrite
 * par {@see ServerAnswersInterface::record()} — le journal d'appels
 * (`mcp_usage`) la lit comme une réponse du serveur, pas comme un 4xx du
 * framework. Écrit deux fois, l'un des deux finissait par perdre une
 * propriété que l'autre gardait.
 *
 * Une fabrique concrète, sans interface : deux appelants d'Infrastructure,
 * aucune variation. Ici et non dans `Mcp/Connection/Application` : elle
 * construit une réponse HTTP, et la couche Application ne dépend pas de
 * HttpFoundation (comme ServerAnswersInterface).
 */
final readonly class UnauthorizedAnswers
{
    public function __construct(
        private BearerChallenge $challenge,
        private ServerAnswersInterface $serverAnswers,
    ) {
    }

    /** Aucun jeton présenté : le défi sans `error=`. */
    public function missingToken(): Response
    {
        return $this->unauthorized($this->challenge->missingToken());
    }

    /** Un jeton présenté et refusé : `error="invalid_token"`, ce qui fait rafraîchir le client. */
    public function refusedToken(): Response
    {
        return $this->unauthorized($this->challenge->refusedToken());
    }

    private function unauthorized(string $challenge): Response
    {
        $response = new Response('', Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => $challenge]);
        $response->headers->addCacheControlDirective('no-store');

        return $this->serverAnswers->record($response);
    }
}
