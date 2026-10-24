<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\AuthorizationRequestValidatorInterface;
use App\Mcp\Connection\Application\IssParameter;
use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
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
 *   langue de la personne, avec les seuls paramètres validés, sous la forme
 *   que league a retenue (AuthorizationRequestSummary::consentQuery()) —
 *   jamais la requête reçue, qu'un autre analyseur lirait autrement. La page
 *   la renverra au serveur, qui la revalidera (tâche 6) ; rien n'est gardé
 *   côté serveur entre les deux.
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
            $summary = $this->validator->validate($this->psrHttpFactory->createRequest($request));
            $response = new RedirectResponse($this->consentPage($request).'?'.http_build_query($summary->consentQuery()));
        } catch (OAuthServerException $refusal) {
            $response = $this->refusal($refusal);
        }

        // Ni la redirection (elle porte `state` et le défi PKCE) ni un refus
        // ne se gardent en cache (RFC 6749 §5.1 pour l'esprit).
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * La page de consentement, dans la langue retenue parmi celles du site
     * (Locale, la liste du projet : une langue ajoutée y est servie sans
     * toucher ici, spec 0004 D2) — jamais une valeur de `Accept-Language`
     * recopiée dans l'URL. La première déclarée vaut par défaut.
     */
    private function consentPage(Request $request): string
    {
        $locales = Locale::values();
        // Jamais nul pour une liste non vide (Symfony rend alors la première
        // langue) ; le repli ne sert qu'au type `?string` de la signature.
        $locale = $request->getPreferredLanguage($locales) ?? Locale::FR->value;

        return $this->endpoints->consentPage($locale);
    }

    /**
     * Le refus rendu par league ; une redirection vers le client y reçoit
     * `iss`.
     *
     * Sans `WWW-Authenticate` : league le pose sur un `invalid_client` quand la
     * requête porte un en-tête `Authorization` (RFC 6749 §5.2, écrit pour le
     * point de jeton, où le client s'authentifie). Ici l'appelant est le
     * navigateur de la personne, et celui qui passe l'authentification Basic
     * de la préproduction la renvoie à chaque requête : `Basic realm="OAuth"`
     * lui ferait ouvrir une demande d'identifiants sur le point
     * d'autorisation. Aucun client ne s'authentifie à ce point.
     */
    private function refusal(OAuthServerException $refusal): Response
    {
        $response = $this->httpFoundationFactory->createResponse(
            $refusal->generateHttpResponse($this->responseFactory->createResponse()),
        );
        $response->headers->remove('WWW-Authenticate');
        $location = $response->headers->get('Location');

        if (null !== $location) {
            $response->headers->set('Location', IssParameter::append($location, $this->endpoints->issuer()));
        }

        return $response;
    }
}
