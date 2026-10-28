<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\ConsentServiceInterface;
use App\Mcp\Connection\Presentation\Dto\ConsentRequest;
use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Le consentement à une connexion MCP (#647, spec 0007 phase 2), servi à la
 * page de consentement du frontend, vers laquelle le point d'autorisation a
 * renvoyé la personne.
 *
 * - `GET /api/oauth/consent?<demande>` : le résumé de la demande, revalidée.
 * - `POST /api/oauth/consent` : la décision de la personne ; la réponse porte
 *   la redirection vers le client, que la page suit. Un 200 JSON plutôt qu'un
 *   302 : la page appelle par `fetch()`, qui suivrait un 302 au lieu de le
 *   rendre — vers un client, hors de l'origine du site.
 *
 * Réservé au palier de confiance par l'access_control `^/api/oauth/consent(/|$)`
 * (ADR 0006 D3 : une connexion lit le corpus nominatif) ; sous le firewall
 * `api`, donc le cookie de la personne, et sous la garde CSRF (double-submit)
 * pour le POST, comme toute mutation de l'API. Un refus de revalidation est un
 * 400 problem+json
 * ({@see \App\Mcp\Connection\Domain\Exception\AuthorizationRequestRejectedException},
 * rendu par {@see \App\Shared\Infrastructure\Http\ApiProblemResponseListener}),
 * jamais une redirection.
 *
 * Léger par construction : conversion PSR-7 de la requête (le pont du bundle
 * MCP, comme AuthorizeController), validation du corps (MapRequestPayload,
 * ConsentRequest) puis sa conversion en commande (ConsentDecision), appel de
 * l'interface, mise en forme. Ni l'une ni l'autre réponse ne se garde
 * en cache : le résumé porte `state` et le défi PKCE, la redirection le code.
 */
final readonly class ConsentController
{
    /**
     * Comment le client est connu du serveur : enregistré à l'avance par la
     * commande `app:mcp:client:register`, le seul mode qui existe (aucun
     * enregistrement dynamique, aucun `registration_endpoint`). La page le dit
     * à la personne ; un autre mode aurait sa propre valeur.
     */
    private const string REGISTRATION = 'preregistered';

    public function __construct(
        private ConsentServiceInterface $consent,
        #[Autowire(service: 'mcp.psr_http_factory')]
        private HttpMessageFactoryInterface $psrHttpFactory,
    ) {
    }

    /**
     * Le résumé nomme aussi le compte qui accorde (#650) : l'identifiant de
     * connexion du `CpgUser` dont le cookie arrive avec CETTE requête, jamais
     * un état que la page aurait gardé — un autre onglet a pu changer de
     * compte entre-temps. L'identifiant est celui de l'appelant lui-même (la
     * route est `ROLE_TRUSTED`, nominatif par nature, ADR 0003) et n'entre dans
     * aucun journal. Même lecture par `#[CurrentUser]` que `decide()` : un
     * autre type d'utilisateur ferait échouer la résolution de l'argument.
     */
    #[Route('/api/oauth/consent', name: 'oauth_consent_show', methods: ['GET'])]
    public function show(Request $request, #[CurrentUser] CpgUser $account): JsonResponse
    {
        $summary = $this->consent->summarize($this->psrHttpFactory->createRequest($request));

        return $this->notStored(new JsonResponse([
            'client' => [
                'id' => $summary->clientId,
                'name' => $summary->clientName,
                'registration' => self::REGISTRATION,
            ],
            'redirectHost' => $summary->redirectHost,
            'scope' => $summary->scope,
            'resource' => $summary->resource,
            'account' => ['username' => $account->getUserIdentifier()],
        ]));
    }

    // JSON seulement : un autre format (formulaire, XML) serait sinon
    // désérialisé et validé, une surface dont l'endpoint n'a aucun usage (415).
    #[Route('/api/oauth/consent', name: 'oauth_consent_decide', methods: ['POST'])]
    /**
     * Un CpgUser par construction : l'access_control exige ROLE_TRUSTED, que
     * le jeton du palier de base ne porte jamais
     * (App\Security\User\Domain\ValueObject\BaseAccessUser). `#[CurrentUser]`
     * le vérifie plutôt que de le supposer : un autre utilisateur ferait
     * échouer la résolution de l'argument, jamais passer un objet d'un autre
     * type à l'accord.
     */
    public function decide(
        #[MapRequestPayload(acceptFormat: 'json')] ConsentRequest $request,
        #[CurrentUser] CpgUser $account,
    ): JsonResponse {
        return $this->notStored(new JsonResponse(['redirectUri' => $this->consent->decide($request->toDecision(), $account)]));
    }

    private function notStored(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
