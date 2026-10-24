<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\RevocationEndpointInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le point de révocation OAuth de la connexion MCP (#647, RFC 7009),
 * `POST /api/oauth/revoke`, appelé par le client MCP lui-même : corps
 * `application/x-www-form-urlencoded`, `token` et `client_id`.
 *
 * Il ne décide de rien : RevocationEndpointInterface juge et révoque, ce
 * contrôleur convertit. Un succès — y compris pour un jeton inconnu, illisible
 * ou d'un autre client (RFC 7009 §2.2) — est un 200 au corps vide ; un refus
 * (`invalid_request`, `invalid_client`), le JSON de RFC 6749 §5.2 que league
 * rend, **rendu ici, jamais levé**, pour la raison qu'en donne
 * TokenController. Aucune réponse ne se garde en cache.
 *
 * Le jeton seul est révoqué, jamais la connexion (#649, #650). Même firewall
 * que le point de jeton (`oauth`, aucun cookie lu), même exclusion de la garde
 * CSRF, même pont PSR-7.
 */
final readonly class RevocationController
{
    public function __construct(
        private RevocationEndpointInterface $endpoint,
        #[Autowire(service: 'mcp.psr_http_factory')]
        private HttpMessageFactoryInterface $psrHttpFactory,
        #[Autowire(service: 'mcp.http_foundation_factory')]
        private HttpFoundationFactoryInterface $httpFoundationFactory,
        #[Autowire(service: 'mcp.psr17_factory')]
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    #[Route('/api/oauth/revoke', name: 'oauth_revoke', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $this->endpoint->revoke($this->psrHttpFactory->createRequest($request));
            $response = new Response('', Response::HTTP_OK);
        } catch (OAuthServerException $refusal) {
            $response = $this->httpFoundationFactory->createResponse(
                $refusal->generateHttpResponse($this->responseFactory->createResponse()),
            );
        }

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
