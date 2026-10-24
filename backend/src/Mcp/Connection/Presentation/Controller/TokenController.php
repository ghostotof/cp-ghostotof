<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\TokenEndpointInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le point de jeton OAuth de la connexion MCP (#647, spec 0007 phase 2),
 * `POST /api/oauth/token`, appelé par le client MCP lui-même, jamais par un
 * navigateur : corps `application/x-www-form-urlencoded` (RFC 6749 §4.1.3),
 * que league lit dans les paramètres analysés.
 *
 * Il ne décide de rien : TokenEndpointInterface juge et émet, ce contrôleur
 * convertit. Un succès est la réponse de league (`token_type`, `expires_in`,
 * `access_token`, `refresh_token`) ; un refus, le JSON de RFC 6749 §5.2 que
 * league rend (`error`, `error_description`, `hint`), **rendu ici, jamais
 * levé** : `ApiProblemResponseListener` et `ApiJsonErrorFormatListener`, sur
 * `kernel.exception`, le réécriraient en problem+json, que les clients OAuth
 * ne lisent pas.
 *
 * Ni l'un ni l'autre ne se garde en cache (RFC 6749 §5.1) : league le dit
 * déjà d'un succès, le contrôleur l'écrit sur toute réponse.
 *
 * Son firewall (`oauth`) n'a aucun authentificateur et ne lit aucun cookie,
 * ce qui rend sûre son exclusion de la garde CSRF
 * ({@see \App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber}) :
 * le `BEARER` d'une personne n'y prête aucune identité. Le pont PSR-7 est
 * celui du bundle MCP, comme AuthorizeController.
 */
final readonly class TokenController
{
    public function __construct(
        private TokenEndpointInterface $endpoint,
        #[Autowire(service: 'mcp.psr_http_factory')]
        private HttpMessageFactoryInterface $psrHttpFactory,
        #[Autowire(service: 'mcp.http_foundation_factory')]
        private HttpFoundationFactoryInterface $httpFoundationFactory,
        #[Autowire(service: 'mcp.psr17_factory')]
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    #[Route('/api/oauth/token', name: 'oauth_token', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $answer = $this->endpoint->respond($this->psrHttpFactory->createRequest($request), $this->responseFactory->createResponse());
        } catch (OAuthServerException $refusal) {
            $answer = $refusal->generateHttpResponse($this->responseFactory->createResponse());
        }

        $response = $this->httpFoundationFactory->createResponse($answer);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
