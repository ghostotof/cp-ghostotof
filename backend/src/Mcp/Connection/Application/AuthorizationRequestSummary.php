<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

/**
 * Une demande d'autorisation validée (#647), résumée pour la page de
 * consentement : ce que la personne doit voir avant d'accepter — le nom
 * **déclaré** du client, l'hôte où partira le code, le scope, la ressource —,
 * et la demande de league, que la tâche 6 complète puis renvoie au client.
 *
 * Tout y a été validé : le client et sa redirection par league (la
 * redirection jugée par McpClient::allowsRedirect()), PKCE par le grant, la
 * ressource par AuthorizationRequestValidator.
 */
final readonly class AuthorizationRequestSummary
{
    /**
     * @param string $redirectUri  la redirection **demandée**, celle vers laquelle le code partira
     * @param string $redirectHost son hôte, tel que League\Uri le lit (`[::1]` entre crochets)
     * @param string $scope        les scopes retenus par league, séparés par une espace (RFC 6749 §3.3)
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public string $redirectUri,
        public string $redirectHost,
        public string $scope,
        public string $resource,
        public AuthorizationRequestInterface $authorizationRequest,
    ) {
    }
}
