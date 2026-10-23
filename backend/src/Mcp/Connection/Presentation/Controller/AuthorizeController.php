<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\AuthorizationRequestValidatorInterface;
use App\Mcp\Connection\Application\IssParameter;
use App\Mcp\Connection\Application\OAuthEndpoints;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le point d'autorisation OAuth de la connexion MCP (#647, spec 0007 phase 2),
 * `GET /api/oauth/authorize`. Il ne décide de rien lui-même : le validateur
 * juge la demande, ce contrôleur choisit la réponse.
 *
 * - Demande valide : 302 vers la page de consentement du frontend, dans la
 *   langue de la personne, la requête d'origine reprise **telle que reçue** —
 *   la page la renverra au serveur, qui la revalidera (tâche 6) ; rien n'est
 *   gardé côté serveur entre les deux.
 * - Refus : le format de league (RFC 6749), pas un problem+json. Avec une
 *   redirection validée, 302 vers le client, `error`, `state` et `iss`
 *   (RFC 9207) ; sans, le JSON de league (400 ou 401), **jamais** de
 *   redirection vers une URI que rien n'a validée.
 *
 * Le refus est rendu ici, pas levé : `ApiProblemResponseListener` et
 * `ApiJsonErrorFormatListener`, sur `kernel.exception`, le réécriraient en
 * problem+json, que les clients OAuth ne lisent pas.
 *
 * Son firewall (`oauth`) n'a aucun authentificateur et ne lit aucun cookie :
 * un `BEARER` de la personne n'y change rien. L'identité de la personne n'est
 * lue qu'au consentement.
 *
 * Le pont PSR-7 est celui du bundle MCP (`mcp.psr_http_factory`,
 * `mcp.http_foundation_factory`, `mcp.psr17_factory`), comme
 * `McpEndpointController` : le framework n'en publie pas, et league ne lit que
 * du PSR-7.
 */
final readonly class AuthorizeController
{
    /** Les deux langues du site ; la première vaut par défaut. */
    private const array LOCALES = ['fr', 'en'];

    public function __construct(
        private AuthorizationRequestValidatorInterface $validator,
        private OAuthEndpoints $endpoints,
        #[Autowire(service: 'mcp.psr_http_factory')]
        private HttpMessageFactoryInterface $psrHttpFactory,
        #[Autowire(service: 'mcp.http_foundation_factory')]
        private HttpFoundationFactoryInterface $httpFoundationFactory,
        #[Autowire(service: 'mcp.psr17_factory')]
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    #[Route('/api/oauth/authorize', name: 'oauth_authorize', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        try {
            $this->validator->validate($this->psrHttpFactory->createRequest($request));
            $response = new RedirectResponse($this->consentPage($request));
        } catch (OAuthServerException $refusal) {
            $response = $this->refusal($refusal);
        }

        // Ni la redirection (elle porte `state` et le défi PKCE) ni un refus
        // ne se gardent en cache (RFC 6749 §5.1 pour l'esprit).
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * La page de consentement, dans la langue retenue parmi celles du site —
     * jamais une valeur de `Accept-Language` recopiée dans l'URL —, suivie de
     * la requête reçue, octet pour octet (`getQueryString()` la trierait et la
     * réencoderait).
     */
    private function consentPage(Request $request): string
    {
        $locale = $request->getPreferredLanguage(self::LOCALES) ?? self::LOCALES[0];

        return $this->endpoints->consentPage($locale).'?'.$request->server->getString('QUERY_STRING');
    }

    /**
     * Le refus rendu par league ; une redirection vers le client y reçoit
     * `iss`.
     */
    private function refusal(OAuthServerException $refusal): Response
    {
        $response = $this->httpFoundationFactory->createResponse(
            $refusal->generateHttpResponse($this->responseFactory->createResponse()),
        );
        $location = $response->headers->get('Location');

        if (null !== $location) {
            $response->headers->set('Location', IssParameter::append($location, $this->endpoints->issuer()));
        }

        return $response;
    }
}
