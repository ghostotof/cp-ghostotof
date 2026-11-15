<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

/**
 * Une demande d'autorisation validée (#647), résumée pour la page de
 * consentement : ce que la personne doit voir avant d'accepter — le nom du
 * client (celui que l'administrateur du site a enregistré, McpClientName),
 * l'hôte où partira le code, le scope, la ressource —,
 * et la demande de league, que la tâche 6 complète puis renvoie au client.
 *
 * Tout y a été validé : le client et sa redirection par league (la
 * redirection jugée par McpClient::allowsRedirect()), PKCE par le grant, la
 * ressource par AuthorizationRequestValidator.
 *
 * Le client est soit une ligne pré-enregistrée, soit — quand le `client_id`
 * est l'URL d'un document que le client publie (CIMD, #651) — un client
 * « déclaré », non persisté, que l'admission a rendu à league : la page de
 * consentement dit alors d'où il tient son identité (registration(),
 * verifiedDomain()).
 */
final readonly class AuthorizationRequestSummary
{
    /**
     * @param string $redirectUri  la redirection **demandée**, telle que league l'a retenue (rognée), celle vers laquelle le code partira
     * @param string $redirectHost son hôte, tel que League\Uri le lit (`[::1]` entre crochets)
     * @param string $scope        les scopes retenus par league, séparés par une espace (RFC 6749 §3.3)
     * @param McpClient|null $discoveredClient le client déclaré que l'admission a rendu à league, `null` pour une ligne pré-enregistrée
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public string $redirectUri,
        public string $redirectHost,
        public string $scope,
        public string $resource,
        public AuthorizationRequestInterface $authorizationRequest,
        public ?McpClient $discoveredClient = null,
    ) {
    }

    /**
     * D'où le client tient son identité : le document qu'il publie (`Cimd`)
     * quand l'admission en a rendu un, la ligne enregistrée sinon.
     */
    public function registration(): ClientRegistration
    {
        return null === $this->discoveredClient ? ClientRegistration::Preregistered : ClientRegistration::Cimd;
    }

    /**
     * Le domaine que le document prouve : l'hôte du `client_id` d'un client
     * déclaré, `null` pour un client pré-enregistré (aucun domaine n'y est
     * vérifié, le nom vient de l'administrateur du site).
     */
    public function verifiedDomain(): ?string
    {
        if (ClientRegistration::Cimd !== $this->registration()) {
            return null;
        }

        return CimdClientId::tryFrom($this->clientId)?->host();
    }

    /**
     * La demande telle que la page de consentement la renverra au serveur :
     * les seuls paramètres validés, sous la forme que league a retenue
     * (rognée, scope par défaut écrit), dans un ordre fixe. Jamais la requête
     * reçue : un paramètre inconnu n'est pas transmis, et la page ne relit
     * que ce que le serveur a jugé. `response_type` vaut `code`, la seule
     * valeur que le grant accepte, comparée à l'identique.
     *
     * @return array<string, string>
     */
    public function consentQuery(): array
    {
        return array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $this->authorizationRequest->getState(),
            'code_challenge' => $this->authorizationRequest->getCodeChallenge(),
            'code_challenge_method' => $this->authorizationRequest->getCodeChallengeMethod(),
            'resource' => $this->resource,
        ], static fn (?string $value): bool => null !== $value);
    }
}
