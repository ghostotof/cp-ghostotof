<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpClient;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * Un client MCP enregistré, vu par league (#647) : public (aucun secret,
 * OAuth 2.1 §2.1), limité aux deux grants que le serveur sert.
 *
 * Il porte le McpClient pour LoopbackAwareAuthCodeGrant, qui juge une
 * redirection demandée par McpClient::allowsRedirect() — la seule règle de
 * comparaison du dépôt — au lieu du validateur de league.
 */
final readonly class OAuthClient implements ClientEntityInterface
{
    /** Les deux grants servis : le code d'autorisation (PKCE) et le rafraîchissement. */
    public const array SERVED_GRANTS = ['authorization_code', 'refresh_token'];

    public function __construct(
        private McpClient $client,
    ) {
    }

    public function mcpClient(): McpClient
    {
        return $this->client;
    }

    /**
     * @return non-empty-string
     */
    public function getIdentifier(): string
    {
        /** @var non-empty-string $clientId jamais vide : `app:mcp:client:register` en exige 1 à 255 caractères */
        $clientId = $this->client->getClientId();

        return $clientId;
    }

    /** Le nom **déclaré** par le client : la page de consentement le marque comme tel. */
    public function getName(): string
    {
        return $this->client->getName();
    }

    /**
     * La liste enregistrée. league ne la compare jamais lui-même ici (la
     * redirection demandée passe par allowsRedirect()) ; il ne s'en sert
     * qu'en l'absence de `redirect_uri`, s'il n'y en a qu'une.
     *
     * @return list<string>
     */
    public function getRedirectUri(): array
    {
        return $this->client->redirectUris();
    }

    public function isConfidential(): bool
    {
        return false;
    }

    /**
     * Lu par league (`AbstractGrant::supportsGrantType()`, par
     * `method_exists`) avant de servir un grant à ce client : tout autre
     * grant est un `unauthorized_client`.
     */
    public function supportsGrantType(string $grantType): bool
    {
        return \in_array($grantType, self::SERVED_GRANTS, true);
    }
}
