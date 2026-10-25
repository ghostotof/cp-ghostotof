<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;

/**
 * Le défi `WWW-Authenticate` de la ressource protégée `/api/mcp` (#648,
 * RFC 6750 §3, RFC 9728 §5.1), écrit en un seul endroit pour ses trois
 * usages :
 *
 *  - {@see self::missingToken()} : aucun jeton présenté, l'appel de l'outil
 *    nominatif déclenche l'autorisation différée (ADR 0006 D3) ;
 *  - {@see self::refusedToken()} : un jeton présenté et refusé, sur toute
 *    requête de `/api/mcp` (décision de l'auteur du 2026-10-24), avec
 *    `error="invalid_token"` (RFC 6750 §3.1) — ce qui fait rafraîchir le
 *    client ;
 *  - {@see self::insufficientScope()} : la forme que l'outil nominatif rend
 *    dans `_meta["mcp/www_authenticate"]` à un appel anonyme marqué `openai/`
 *    (ADR 0006 D3).
 *
 * Chacun porte `resource_metadata`, l'URL du document de métadonnées de la
 * ressource (RFC 9728), que le client lit pour trouver le serveur
 * d'autorisation — lue dans OAuthEndpoints, jamais recomposée —, et le seul
 * scope qu'une connexion accorde. Toutes les valeurs sont des chaînes entre
 * guillemets (RFC 7235 `quoted-string`) : l'URL et le scope n'y mettent ni
 * guillemet ni barre oblique inverse, la description est vérifiée.
 */
final readonly class BearerChallenge
{
    /** L'alphabet d'`error_description` (RFC 6749 A.2) : `1*( %x20-21 / %x23-5B / %x5D-7E )`. */
    private const string DESCRIPTION_PATTERN = '/^[\x20\x21\x23-\x5B\x5D-\x7E]+\z/';

    public function __construct(
        private OAuthEndpoints $endpoints,
    ) {
    }

    public function missingToken(): string
    {
        return \sprintf('Bearer resource_metadata="%s", scope="%s"', $this->endpoints->protectedResourceMetadataUrl(), McpConnection::SCOPE_NOMINATIVE_READ);
    }

    public function refusedToken(): string
    {
        return $this->missingToken().', error="invalid_token"';
    }

    /**
     * @throws InvalidChallengeDescriptionException quand `$description` sort de l'alphabet de RFC 6749 A.2
     */
    public function insufficientScope(string $description): string
    {
        if (1 !== preg_match(self::DESCRIPTION_PATTERN, $description)) {
            throw InvalidChallengeDescriptionException::outsideTheRfcCharacterSet();
        }

        return \sprintf('%s, error="insufficient_scope", error_description="%s"', $this->missingToken(), $description);
    }
}
